<?php

namespace App\Domains;

use App\Billing\CreditApplier;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Events\InvoiceIssued;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Domain renewal invoices, and what paying a domain line does. An
 * auto-renewing domain gets its renewal invoice ahead of its expiry date;
 * the others are renewed when the client asks. Each renewal period is
 * invoiced at most once, so running this again on the same day is safe.
 */
class DomainRenewals
{
    public function __construct(private CreditApplier $credit, private RegistrarActions $actions) {}

    /** @return Collection<int, Invoice> */
    public function generate(CarbonInterface $today): Collection
    {
        $cutoff = $today->copy()->addDays((int) config('domains.invoice_days_before_expiry'));

        return $this->dueDomains($cutoff)
            ->distinct()
            ->pluck('client_id')
            ->map(fn (int $clientId) => DB::transaction(function () use ($clientId, $cutoff, $today) {
                $client = Client::lockForUpdate()->findOrFail($clientId);
                $domains = $this->dueDomains($cutoff)->where('client_id', $clientId)->orderBy('id')->get();

                return $domains->isEmpty() ? null : $this->invoice($client, $domains, $today);
            }))
            ->filter()
            ->each(fn (Invoice $invoice) => $this->issue($invoice))
            ->values();
    }

    /**
     * A renewal invoice for one domain, as the client asks in the portal.
     * An unpaid renewal invoice already there is returned instead.
     *
     * @throws InvalidArgumentException when the domain can't be renewed for that long.
     */
    public function renewNow(Domain $domain, int $years): Invoice
    {
        if (! in_array($domain->status, DomainStatus::live(), true) || $domain->next_due_date === null) {
            throw new InvalidArgumentException(__(':name can only be renewed once it is registered.', ['name' => $domain->name]));
        }

        if ($open = $this->openRenewal($domain)) {
            return $open;
        }

        $currency = $domain->client->currency;
        $amount = $domain->tld ? DomainOrders::renewalAmount($domain->tld->loadMissing('prices'), $currency, $years) : null;
        if ($years === $domain->years) {
            $amount = $domain->recurring_amount;
        } elseif ($amount !== null && $domain->id_protection) {
            $amount += DomainOrders::protectionAmount($domain->tld, $currency, $years);
        }
        if ($amount === null) {
            throw new InvalidArgumentException(__(':name can\'t be renewed for :years year(s).', ['name' => $domain->name, 'years' => $years]));
        }

        $invoice = DB::transaction(function () use ($domain, $years, $amount) {
            $client = Client::lockForUpdate()->findOrFail($domain->client_id);

            return $this->invoice($client, collect([$domain]), today(), [$domain->id => [$years, $amount]]);
        });
        $this->issue($invoice);

        return $invoice->refresh();
    }

    /** The unpaid invoice renewing the domain's current period, if there is one. */
    public function openRenewal(Domain $domain): ?Invoice
    {
        return Invoice::where('status', InvoiceStatus::Unpaid)
            ->whereHas('items', fn (Builder $items) => $items->where('domain_id', $domain->id)
                ->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)
                ->whereDate('period_start', $domain->next_due_date))
            ->first();
    }

    /** Whether the renewal of the domain's current period is paid, and only waits on the registrar. */
    public static function renewalPaid(Domain $domain): bool
    {
        return $domain->next_due_date !== null && $domain->invoiceItems()
            ->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)
            ->whereDate('period_start', $domain->next_due_date)
            ->whereHas('invoice', fn (Builder $invoice) => $invoice->where('status', InvoiceStatus::Paid))
            ->exists();
    }

    /**
     * Acts on a paid invoice's domain lines: registers or transfers new
     * domains (unless their order waits for staff review) and renews the rest.
     */
    public function paid(Invoice $invoice): void
    {
        $items = $invoice->items()->whereIn('type', InvoiceItem::DOMAIN_TYPES)->whereNotNull('domain_id')->with('domain.order')->get();

        foreach ($items as $item) {
            $domain = $item->domain;

            if ($item->type === InvoiceItem::TYPE_DOMAIN_RENEW) {
                if ($domain->next_due_date?->equalTo($item->period_start)) {
                    $years = (int) round($item->period_start->diffInYears($item->period_end->copy()->addDay()));
                    $this->actions->queue($domain, 'renew', max(1, $years));
                }
            } elseif ($domain->status === DomainStatus::Pending && ! $this->awaitingReview($domain)) {
                $this->start($domain);
            }
        }
    }

    /** Sends a new domain to its registrar: a registration, or a transfer in. */
    public function start(Domain $domain): void
    {
        $this->actions->queue($domain, $domain->type === Domain::TRANSFER ? 'transfer' : 'register');
    }

    private function awaitingReview(Domain $domain): bool
    {
        return config('billing.hold_new_orders') && $domain->order?->status === OrderStatus::Pending;
    }

    private function dueDomains(CarbonInterface $cutoff): Builder
    {
        return Domain::query()
            ->whereIn('status', DomainStatus::live())
            ->where('auto_renew', true)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $cutoff)
            ->whereDoesntHave('invoiceItems', fn (Builder $items) => $items
                ->where('type', InvoiceItem::TYPE_DOMAIN_RENEW)
                ->whereColumn('invoice_items.period_start', 'domains.next_due_date')
                ->whereHas('invoice', fn (Builder $invoice) => $invoice->where('status', '!=', InvoiceStatus::Cancelled)));
    }

    /**
     * @param  Collection<int, Domain>  $domains
     * @param  array<int, array{0: int, 1: int}>  $terms  Years and amount by domain id, when not the domain's own.
     */
    private function invoice(Client $client, Collection $domains, CarbonInterface $today, array $terms = []): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Unpaid,
            'currency' => $client->currency,
            'issue_date' => $today,
            'due_date' => $domains->min('next_due_date')->max($today),
        ]);

        foreach ($domains as $domain) {
            [$years, $amount] = $terms[$domain->id] ?? [$domain->years, $domain->recurring_amount];
            $start = $domain->next_due_date;
            $end = $start->copy()->addYears($years)->subDay();
            $invoice->items()->create([
                'domain_id' => $domain->id,
                'type' => InvoiceItem::TYPE_DOMAIN_RENEW,
                'description' => DomainOrders::description($domain, 'renew', $years, $start, $end),
                'amount' => $amount,
                'period_start' => $start,
                'period_end' => $end,
            ]);
        }

        $invoice->recalculate();
        Activity::record("Generated domain renewal invoice #{$invoice->number} for ".Money::format($invoice->total, $invoice->currency), $invoice);

        return $invoice;
    }

    private function issue(Invoice $invoice): void
    {
        $this->credit->applyIfEnabled($invoice);
        InvoiceIssued::dispatch($invoice->refresh());
    }
}
