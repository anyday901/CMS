<?php

namespace App\Billing;

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
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Moves a service to another product on the same billing cycle. The rest of
 * the paid period is prorated by day: a move that costs more is invoiced and
 * applied once that invoice is paid; one that costs the same or less is
 * applied at once, with the difference added to the client's credit.
 */
class ServiceUpgrader
{
    public function __construct(
        private ConfigurableOptions $options,
        private CreditLedger $ledger,
        private CreditApplier $credit,
    ) {}

    /** Why this service can't be moved right now, or null when it can. */
    public function problem(Service $service): ?string
    {
        return match (true) {
            $service->status !== ServiceStatus::Active => 'Only active services can be upgraded or downgraded.',
            ! $service->billing_cycle->isRecurring() || $service->next_due_date === null => 'One-time services can\'t be upgraded or downgraded.',
            $service->next_due_date->lte(today()) => 'Pay the renewal for this service first.',
            $this->hasUnpaidInvoice($service) => 'This service has an unpaid invoice. Pay or wait for it to be cancelled first.',
            default => null,
        };
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

        $currency = $service->client->currency;
        $cycle = $service->billing_cycle;
        $price = $product->priceFor($cycle, $currency)
            ?? throw new InvalidArgumentException("{$product->name} has no {$cycle->label()} price in {$currency}.");
        $selected = $this->options->select($product, $options, $cycle);
        $this->options->assertCurrency($selected, $currency);
        $recurring = $price->price + $this->options->recurring($selected);

        // A recurring promotion carries over if it covers the new product too.
        $promotion = $service->recurring_discount > 0 ? $service->promotion : null;
        $discount = $promotion?->appliesTo($product) ? $promotion->discountOn($recurring) : 0;

        $periodEnd = $service->next_due_date;
        $periodStart = $periodEnd->copy()->subMonthsNoOverflow($cycle->months());
        $periodDays = (int) $periodStart->diffInDays($periodEnd);
        $daysLeft = (int) today()->diffInDays($periodEnd);

        return new UpgradeQuote(
            $product,
            $selected,
            $recurring,
            $discount,
            $daysLeft,
            $periodDays,
            $this->prorate($service->netRecurring(), $daysLeft, $periodDays),
            $this->prorate(max(0, $recurring - $discount), $daysLeft, $periodDays),
        );
    }

    /**
     * Invoices the move or applies it now.
     *
     * @param  array<int|string, mixed>  $options
     */
    public function request(Service $service, Product $product, array $options = []): ServiceUpgrade
    {
        $quote = $this->quote($service, $product, $options);

        $upgrade = DB::transaction(function () use ($service, $quote) {
            $service = Service::lockForUpdate()->findOrFail($service->id);

            if ($problem = $this->problem($service)) {
                throw new InvalidArgumentException($problem);
            }

            $upgrade = $service->upgrades()->create([
                'from_product_id' => $service->product_id,
                'to_product_id' => $quote->product->id,
                'from_amount' => $service->recurring_amount,
                'to_amount' => $quote->recurring,
                'options' => $quote->options->map(fn ($chosen) => $chosen->only(['product_option_id', 'product_option_choice_id', 'quantity', 'unit_price']))->values()->all(),
                'prorated_amount' => $quote->net(),
            ]);

            if ($quote->net() > 0) {
                $upgrade->update(['invoice_id' => $this->invoice($service, $quote)->id]);
            }

            return $upgrade;
        });

        if ($upgrade->invoice) {
            Activity::record("Requested a move of {$service->description()} to {$quote->product->name}, invoiced on #{$upgrade->invoice->number}", $service);
            $this->credit->applyIfEnabled($upgrade->invoice);
            InvoiceIssued::dispatch($upgrade->invoice->refresh());

            return $upgrade->refresh();
        }

        $this->apply($upgrade);

        return $upgrade->refresh();
    }

    /**
     * Moves the service to its new product. Called at once for a move that
     * costs nothing, or when the move's invoice is paid. Safe to call twice.
     */
    public function apply(ServiceUpgrade $upgrade): void
    {
        $applied = DB::transaction(function () use ($upgrade) {
            $upgrade = ServiceUpgrade::lockForUpdate()->findOrFail($upgrade->id);

            if ($upgrade->status !== ServiceUpgrade::PENDING) {
                return null;
            }

            $service = Service::lockForUpdate()->with(['client', 'promotion'])->findOrFail($upgrade->service_id);
            $from = $service->description();
            $product = Product::findOrFail($upgrade->to_product_id);
            $promotion = $service->recurring_discount > 0 ? $service->promotion : null;
            $discount = $promotion?->appliesTo($product) ? $promotion->discountOn($upgrade->to_amount) : 0;

            $service->options()->delete();
            $service->options()->createMany($upgrade->options ?? []);
            $service->forceFill([
                'product_id' => $product->id,
                'recurring_amount' => $upgrade->to_amount,
                'recurring_discount' => $discount,
                'promotion_id' => $discount > 0 ? $promotion->id : null,
            ])->save();

            if ($upgrade->prorated_amount < 0) {
                $this->ledger->change($service->client_id, -$upgrade->prorated_amount, "Unused time on {$from}, moved to {$product->name}");
            }

            $upgrade->update(['status' => ServiceUpgrade::APPLIED, 'applied_at' => now()]);
            Activity::record("Moved {$from} to {$product->name}", $service, [
                'recurring_amount' => ['from' => $upgrade->from_amount, 'to' => $upgrade->to_amount],
                'prorated' => $upgrade->prorated_amount,
            ]);

            return $service;
        });

        if ($applied) {
            ServiceChanged::dispatch($applied->refresh());
        }
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
            'description' => Str::limit(sprintf(
                'Move %s to %s (%s - %s, %s for %d of %d days, less %s unused)',
                $service->description(),
                $quote->product->name,
                today()->format('m/d/Y'),
                $service->next_due_date->copy()->subDay()->format('m/d/Y'),
                Money::format($quote->charge, $invoice->currency),
                $quote->daysLeft,
                $quote->periodDays,
                Money::format($quote->credit, $invoice->currency),
            ), 255, ''),
            'amount' => $quote->net(),
            'taxable' => $quote->product->taxable,
        ]);

        return $invoice->recalculate();
    }

    /** $amount for $days of $periodDays, rounded half up. */
    private function prorate(int $amount, int $days, int $periodDays): int
    {
        return $periodDays > 0 ? intdiv($amount * $days * 2 + $periodDays, 2 * $periodDays) : 0;
    }

    private function hasUnpaidInvoice(Service $service): bool
    {
        return Invoice::whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Draft])
            ->whereHas('items', fn ($items) => $items->where('service_id', $service->id))
            ->exists();
    }
}
