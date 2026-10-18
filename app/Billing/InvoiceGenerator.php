<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\InvoiceIssued;
use App\Models\Activity;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates renewal invoices for services coming due. Each service period is
 * invoiced at most once, so running this repeatedly on the same day is safe.
 */
class InvoiceGenerator
{
    public function __construct(private CreditApplier $credit) {}

    /** @return Collection<int, Invoice> */
    public function generate(CarbonInterface $today): Collection
    {
        $cutoff = $today->copy()->addDays((int) config('billing.invoice_days_before_due'));

        return $this->dueServices($cutoff)
            ->distinct()
            ->pluck('client_id')
            ->flatMap(fn (int $clientId) => $this->invoiceClient($clientId, $cutoff, $today))
            ->each(fn (Invoice $invoice) => $this->credit->applyIfEnabled($invoice))
            ->map(fn (Invoice $invoice) => $invoice->refresh())
            ->each(fn (Invoice $invoice) => InvoiceIssued::dispatch($invoice))
            ->values();
    }

    private function dueServices(CarbonInterface $cutoff): Builder
    {
        return Service::query()
            ->whereIn('status', ServiceStatus::billable())
            ->where('billing_cycle', '!=', BillingCycle::OneTime)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $cutoff)
            // Set to be cancelled, or an add-on of one that is, so not renewed.
            ->whereDoesntHave('cancellationRequests', fn (Builder $requests) => $requests->where('status', CancellationRequest::PENDING))
            ->whereDoesntHave('parent.cancellationRequests', fn (Builder $requests) => $requests->where('status', CancellationRequest::PENDING))
            ->whereDoesntHave('invoiceItems', function (Builder $items) {
                $items->whereColumn('invoice_items.period_start', 'services.next_due_date')
                    ->whereHas('invoice', fn (Builder $invoice) => $invoice
                        ->where('status', '!=', InvoiceStatus::Cancelled));
            });
    }

    /**
     * Locks the client row so concurrent runs serialize per client, then
     * re-reads which services still need invoicing before creating anything.
     * Usually one invoice for all of them; one each when the client's group
     * wants separate invoices.
     *
     * @return list<Invoice>
     */
    private function invoiceClient(int $clientId, CarbonInterface $cutoff, CarbonInterface $today): array
    {
        return DB::transaction(function () use ($clientId, $cutoff, $today) {
            $client = Client::with('group')->lockForUpdate()->findOrFail($clientId);

            $services = $this->dueServices($cutoff)
                ->where('client_id', $clientId)
                ->with(['product', 'promotion', 'options.option', 'options.choice'])
                ->orderBy('id')
                ->get();

            if ($services->isEmpty()) {
                return [];
            }

            $batches = $client->group?->separate_invoices ? $services->chunk(1) : [$services];

            return collect($batches)->map(fn (Collection $batch) => $this->createInvoice($client, $batch, $today))->all();
        });
    }

    /** @param  Collection<int, Service>  $services */
    private function createInvoice(Client $client, Collection $services, CarbonInterface $today): Invoice
    {
        $invoice = Invoice::create([
            'client_id' => $client->id,
            'status' => InvoiceStatus::Unpaid,
            'currency' => $client->currency,
            'issue_date' => $today,
            'due_date' => $services->min('next_due_date'),
        ]);
        // New orders always get the group's discount; renewals only when the group says so.
        $group = $client->group?->discount_renewals ? $client->group : null;

        foreach ($services as $service) {
            $start = $service->next_due_date;
            $end = $service->billing_cycle->advance($start)->subDay();

            $invoice->items()->create([
                'service_id' => $service->id,
                'type' => InvoiceItem::TYPE_SERVICE,
                'description' => InvoiceItem::serviceDescription($service, $start, $end),
                'amount' => $service->recurring_amount,
                'taxable' => $service->product->taxable,
                'period_start' => $start,
                'period_end' => $end,
            ]);

            $promotion = 0;
            if ($service->recurring_discount > 0) {
                $promotion = min($service->recurring_discount, $service->recurring_amount);
                $invoice->items()->create([
                    'service_id' => $service->id,
                    'type' => InvoiceItem::TYPE_PROMOTION,
                    'description' => InvoiceItem::promotionDescription($service, $service->promotion?->code),
                    'amount' => -$promotion,
                    'taxable' => $service->product->taxable,
                ]);
            }
            GroupDiscount::addTo($invoice, $service, $group, $service->recurring_amount - $promotion, $service->product->taxable);
        }

        $invoice->recalculate();
        Activity::record("Generated invoice #{$invoice->number} for ".Money::format($invoice->total, $invoice->currency), $invoice);

        return $invoice;
    }
}
