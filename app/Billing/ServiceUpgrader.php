<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\InvoiceIssued;
use App\Events\ServiceChanged;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\TaxRule;
use App\Models\User;
use App\Provisioning\ModuleRegistry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moves a service to another product. Clients move on the same billing
 * cycle, to the products set as upgrades. The rest of the paid period is
 * prorated by day: a move that costs more is invoiced and applied once that
 * invoice is paid; one that costs the same or less is applied at once, with
 * the difference added to the client's credit.
 *
 * Staff can move a service to any product and billing cycle, with or
 * without charging. A charged move to another cycle credits the unused time
 * and invoices a whole period of the new cycle starting today; one without
 * charging keeps the next due date and bills the new price from then.
 */
class ServiceUpgrader
{
    private const DATE = 'm/d/Y';

    public function __construct(
        private ConfigurableOptions $options,
        private CreditLedger $ledger,
        private CreditApplier $credit,
        private ModuleRegistry $modules,
    ) {}

    /** Why the client can't move this service right now, or null when they can. */
    public function problem(Service $service): ?string
    {
        if ($service->status !== ServiceStatus::Active) {
            return 'Only active services can be upgraded or downgraded.';
        }

        return $this->moveProblem($service) ?? $this->prorateProblem($service);
    }

    /** Why staff can't move this service right now, charging for it or not, or null when they can. */
    public function staffProblem(Service $service, bool $charge): ?string
    {
        if (! in_array($service->status, ServiceStatus::billable(), true)) {
            return 'Only active or suspended services can be moved.';
        }

        return $this->moveProblem($service) ?? ($charge ? $this->prorateProblem($service) : $this->pendingMoveProblem($service));
    }

    /**
     * @param  array<int|string, mixed>  $options  Configurable option values for the new product.
     *
     * @throws InvalidArgumentException when the move isn't allowed.
     */
    public function quote(Service $service, Product $product, array $options = []): UpgradeQuote
    {
        if ($problem = $this->problem($service)) {
            throw new InvalidArgumentException($problem);
        }

        if (! $service->product->upgrades()->whereKey($product->id)->where('active', true)->exists()) {
            throw new InvalidArgumentException("{$service->product->name} can't be moved to {$product->name}.");
        }

        return $this->price($service, $product, $service->billing_cycle, $options, true);
    }

    /**
     * A move staff make, to any product and recurring cycle.
     *
     * @param  array<int|string, mixed>  $options
     *
     * @throws InvalidArgumentException when the move isn't allowed.
     */
    public function staffQuote(Service $service, Product $product, BillingCycle $cycle, array $options, bool $charge): UpgradeQuote
    {
        if ($problem = $this->staffProblem($service, $charge)) {
            throw new InvalidArgumentException($problem);
        }

        if (! $cycle->isRecurring()) {
            throw new InvalidArgumentException('Choose a recurring billing cycle.');
        }

        return $this->price($service, $product, $cycle, $options, $charge);
    }

    /**
     * Invoices the client's move or applies it now.
     *
     * @param  array<int|string, mixed>  $options
     */
    public function request(Service $service, Product $product, array $options = []): ServiceUpgrade
    {
        return $this->record($service, $this->quote($service, $product, $options), null, fn (Service $locked) => $this->problem($locked));
    }

    /**
     * Makes a staff move: invoiced when it's charged and costs more,
     * applied at once otherwise.
     *
     * @param  array<int|string, mixed>  $options
     */
    public function staffMove(Service $service, Product $product, BillingCycle $cycle, array $options, bool $charge, User $staff): ServiceUpgrade
    {
        $quote = $this->staffQuote($service, $product, $cycle, $options, $charge);

        return $this->record($service, $quote, $staff, fn (Service $locked) => $this->staffProblem($locked, $charge));
    }

    /**
     * Saves the move, then invoices it or applies it.
     *
     * @param  Closure(Service): ?string  $recheck  Why the move can't go ahead now, with the service locked.
     */
    private function record(Service $service, UpgradeQuote $quote, ?User $staff, Closure $recheck): ServiceUpgrade
    {
        $upgrade = DB::transaction(function () use ($service, $quote, $staff, $recheck) {
            $service = Service::lockForUpdate()->findOrFail($service->id);

            if ($problem = $recheck($service)) {
                throw new InvalidArgumentException($problem);
            }

            $cycleChange = $quote->cycle !== $service->billing_cycle;
            $upgrade = $service->upgrades()->create([
                'from_product_id' => $service->product_id,
                'to_product_id' => $quote->product->id,
                'from_amount' => $service->recurring_amount,
                'to_amount' => $quote->recurring,
                'from_cycle' => $cycleChange ? $service->billing_cycle->value : null,
                'to_cycle' => $cycleChange ? $quote->cycle->value : null,
                'next_due_date' => $quote->nextDue,
                'options' => $quote->options->map(fn ($chosen) => $chosen->only(['product_option_id', 'product_option_choice_id', 'quantity', 'unit_price']))->values()->all(),
                'prorated_amount' => $quote->net(),
                'moved_by' => $staff?->id,
            ]);

            if ($quote->net() > 0) {
                $upgrade->update(['invoice_id' => $this->invoice($service, $quote)->id]);
            } else {
                // Still holding the service lock, so a second request can't apply a move too.
                $this->apply($upgrade);
            }

            return $upgrade;
        });

        if ($upgrade->invoice) {
            Activity::record("Requested a move of {$service->description()} to {$quote->product->name}, invoiced on #{$upgrade->invoice->number}", $service);
            $this->credit->applyIfEnabled($upgrade->invoice);
            InvoiceIssued::dispatch($upgrade->invoice->refresh());
        }

        return $upgrade->refresh();
    }

    /**
     * Moves the service to its new product (and cycle). Called at once for a
     * move that costs nothing, or when the move's invoice is paid. Safe to
     * call twice.
     */
    public function apply(ServiceUpgrade $upgrade): void
    {
        $changed = DB::transaction(function () use ($upgrade) {
            $upgrade = ServiceUpgrade::lockForUpdate()->findOrFail($upgrade->id);

            if ($upgrade->status !== ServiceUpgrade::PENDING) {
                return null;
            }

            $service = Service::lockForUpdate()->with(['client', 'promotion', 'product'])->findOrFail($upgrade->service_id);
            $from = $service->description();
            $product = Product::findOrFail($upgrade->to_product_id);
            $packageChanged = $this->move($service, $upgrade, $product);

            if ($upgrade->prorated_amount < 0) {
                $this->ledger->change($service->client_id, $this->creditWithTax($service, $upgrade->fromProduct, -$upgrade->prorated_amount), "Unused time on {$from}, moved to {$product->name}");
            }

            $upgrade->update(['status' => ServiceUpgrade::APPLIED, 'applied_at' => now()]);
            Activity::record("Moved {$from} to {$product->name}".($upgrade->to_cycle ? ', '.strtolower($service->billing_cycle->label()) : ''), $service, array_filter([
                'recurring_amount' => ['from' => $upgrade->from_amount, 'to' => $upgrade->to_amount],
                'billing_cycle' => $upgrade->to_cycle ? ['from' => $upgrade->from_cycle, 'to' => $upgrade->to_cycle] : null,
                'prorated' => $upgrade->prorated_amount,
            ], fn ($value) => $value !== null));

            return $packageChanged ? $service : null;
        });

        // Only a new product or options needs the module; a new cycle is billing alone.
        if ($changed) {
            ServiceChanged::dispatch($changed->refresh());
        }
    }

    /** @return bool Whether the product or its options changed. */
    private function move(Service $service, ServiceUpgrade $upgrade, Product $product): bool
    {
        $before = [$service->product_id, $service->optionSummary()];
        $promotion = $service->recurring_discount > 0 ? $service->promotion : null;
        $discount = $promotion?->appliesTo($product) ? $promotion->discountOn($upgrade->to_amount) : 0;

        $service->options()->delete();
        $service->options()->createMany($upgrade->options ?? []);
        $service->forceFill([
            'product_id' => $product->id,
            'recurring_amount' => $upgrade->to_amount,
            'recurring_discount' => $discount,
            'promotion_id' => $discount > 0 ? $promotion->id : null,
            'billing_cycle' => $upgrade->to_cycle ?? $service->billing_cycle,
            'next_due_date' => $upgrade->next_due_date ?? $service->next_due_date,
        ])->save();

        return $before !== [$service->product_id, $service->load('options')->optionSummary()];
    }

    private function invoice(Service $service, UpgradeQuote $quote): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $service->client_id,
            'status' => InvoiceStatus::Unpaid,
            'currency' => $service->client->currency,
            'issue_date' => today(),
            'due_date' => today(),
        ]);

        $invoice->items()->create([
            'service_id' => $service->id,
            'type' => InvoiceItem::TYPE_UPGRADE,
            'description' => Str::limit($this->moveDescription($service, $quote, $invoice->currency), 255, ''),
            'amount' => $quote->net(),
            'taxable' => $quote->product->taxable,
        ]);

        return $invoice->recalculate();
    }

    private function moveDescription(Service $service, UpgradeQuote $quote, string $currency): string
    {
        $proration = $quote->proration;
        $unused = Money::format($proration->credit, $currency);

        if ($quote->nextDue !== null) {
            return sprintf(
                'Move %s to %s, %s (%s - %s, %s, less %s unused for %d of %d days)',
                $service->description(),
                $quote->product->name,
                strtolower($quote->cycle->label()),
                today()->format(self::DATE),
                $quote->nextDue->copy()->subDay()->format(self::DATE),
                Money::format($proration->charge, $currency),
                $unused,
                $proration->daysLeft,
                $proration->periodDays,
            );
        }

        return sprintf(
            'Move %s to %s (%s - %s, %s for %d of %d days, less %s unused)',
            $service->description(),
            $quote->product->name,
            today()->format(self::DATE),
            $service->next_due_date->copy()->subDay()->format(self::DATE),
            Money::format($proration->charge, $currency),
            $proration->daysLeft,
            $proration->periodDays,
            $unused,
        );
    }

    /**
     * The credit for a cheaper move. With tax-exclusive pricing the unused
     * time was paid for with tax on top, so that tax is credited too, at the
     * client's current rate; tax-inclusive amounts already contain it.
     */
    private function creditWithTax(Service $service, Product $from, int $amount): int
    {
        if (config('billing.tax_inclusive') || ! $from->taxable) {
            return $amount;
        }

        return $amount + TaxRule::taxOn($amount, TaxRule::forClient($service->client)?->rate ?? 0);
    }

    /**
     * Where the paid period ending at the next due date began. Taken from the
     * invoice line that billed it, since month-end dates are clamped going
     * forward (Jan 31 to Feb 28) and can't be worked back from the due date.
     */
    private function periodStart(Service $service): CarbonInterface
    {
        $billed = $service->invoiceItems()
            ->where('type', InvoiceItem::TYPE_SERVICE)
            ->whereDate('period_end', $service->next_due_date->copy()->subDay())
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', InvoiceStatus::Paid))
            ->latest('id')
            ->value('period_start');

        $start = $billed !== null ? CarbonImmutable::parse($billed) : null;

        // A first invoice prorated to the product's billing day covers a part
        // period (or a part and a whole one), so the move is prorated over the
        // whole cycle ending at the due date instead.
        return $start !== null && $service->billing_cycle->advance($start)->equalTo($service->next_due_date)
            ? $start
            : $service->next_due_date->copy()->subMonthsNoOverflow($service->billing_cycle->months());
    }

    /**
     * Prices a move. Without charging, nothing is prorated and the next due
     * date stays. A charged move to another cycle bills a whole new period
     * from today.
     *
     * @param  array<int|string, mixed>  $options
     */
    private function price(Service $service, Product $product, BillingCycle $cycle, array $options, bool $charge): UpgradeQuote
    {
        if (! $this->modules->canMove($service->product->module, $product->module)) {
            throw new InvalidArgumentException("This service can't be moved to {$product->name}. Order it instead, then cancel this service.");
        }

        $currency = $service->client->currency;
        $price = $product->priceFor($cycle, $currency)
            ?? throw new InvalidArgumentException("{$product->name} has no {$cycle->label()} price in {$currency}.");
        $selected = $this->options->select($product, $options, $cycle);
        $this->options->assertCurrency($selected, $currency);
        $recurring = $price->price + $this->options->recurring($selected);

        if ($product->id === $service->product_id && $cycle === $service->billing_cycle) {
            throw new InvalidArgumentException('Choose a different product or billing cycle.');
        }

        // A recurring promotion carries over if it covers the new product too.
        $promotion = $service->recurring_discount > 0 ? $service->promotion : null;
        $discount = $promotion?->appliesTo($product) ? $promotion->discountOn($recurring) : 0;
        $newNet = max(0, $recurring - $discount);

        if (! $charge) {
            return new UpgradeQuote($product, $selected, $cycle, $recurring, $discount, new Proration);
        }

        $periodEnd = $service->next_due_date;
        $periodDays = (int) $this->periodStart($service)->diffInDays($periodEnd);
        $daysLeft = (int) today()->diffInDays($periodEnd);
        $credit = Money::prorate($service->netRecurring(), $daysLeft, $periodDays);

        if ($cycle !== $service->billing_cycle) {
            return new UpgradeQuote($product, $selected, $cycle, $recurring, $discount, new Proration($daysLeft, $periodDays, $credit, $newNet), $cycle->advance(today()));
        }

        return new UpgradeQuote($product, $selected, $cycle, $recurring, $discount, new Proration($daysLeft, $periodDays, $credit, Money::prorate($newNet, $daysLeft, $periodDays)));
    }

    /** What stops any move: a service with no cycle to move on, or one set to be cancelled. */
    private function moveProblem(Service $service): ?string
    {
        return match (true) {
            ! $service->billing_cycle->isRecurring() || $service->next_due_date === null => 'One-time services can\'t be upgraded or downgraded.',
            $service->pendingCancellation()->exists() => 'This service is set to be cancelled.',
            default => null,
        };
    }

    /** What stops a move that prorates the paid period. */
    private function prorateProblem(Service $service): ?string
    {
        return match (true) {
            $service->next_due_date->lte(today()) => 'Pay the renewal for this service first.',
            $service->inTrial() => 'Upgrades and downgrades are available once the free trial ends.',
            $this->hasUnpaidInvoice($service) => 'This service has an unpaid invoice. Pay or wait for it to be cancelled first.',
            default => null,
        };
    }

    /** A move without charging would be overwritten by one still waiting on its invoice. */
    private function pendingMoveProblem(Service $service): ?string
    {
        return $service->upgrades()->where('status', ServiceUpgrade::PENDING)->exists()
            ? 'This service has a move waiting on its invoice. Cancel that invoice first.'
            : null;
    }

    private function hasUnpaidInvoice(Service $service): bool
    {
        return Invoice::whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Draft])
            ->whereHas('items', fn ($items) => $items->where('service_id', $service->id))
            ->exists();
    }
}
