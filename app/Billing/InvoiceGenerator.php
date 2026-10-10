<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Activity;
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
            ->map(fn (int $clientId) => $this->invoiceClient($clientId, $cutoff, $today))
            ->filter()
            ->each(fn (Invoice $invoice) => $this->credit->applyIfEnabled($invoice))
            ->map(fn (Invoice $invoice) => $invoice->refresh())
            ->values();
    }

    private function dueServices(CarbonInterface $cutoff): Builder
    {
        return Service::query()
            ->whereIn('status', ServiceStatus::billable())
            ->where('billing_cycle', '!=', BillingCycle::OneTime)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $cutoff)
            ->whereDoesntHave('invoiceItems', function (Builder $items) {
                $items->whereColumn('invoice_items.period_start', 'services.next_due_date')
                    ->whereHas('invoice', fn (Builder $invoice) => $invoice
                        ->where('status', '!=', InvoiceStatus::Cancelled));
            });
    }

    /**
     * Locks the client row so concurrent runs serialize per client, then
     * re-reads which services still need invoicing before creating anything.
     */
    private function invoiceClient(int $clientId, CarbonInterface $cutoff, CarbonInterface $today): ?Invoice
    {
        return DB::transaction(function () use ($clientId, $cutoff, $today) {
            $client = Client::lockForUpdate()->findOrFail($clientId);

            $services = $this->dueServices($cutoff)
                ->where('client_id', $clientId)
                ->with('product')
                ->orderBy('id')
                ->get();

            if ($services->isEmpty()) {
                return null;
            }

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => $today,
                'due_date' => $services->min('next_due_date'),
            ]);

            foreach ($services as $service) {
                $start = $service->next_due_date;
                $end = $service->billing_cycle->advance($start)->subDay();

                $invoice->items()->create([
                    'service_id' => $service->id,
                    'type' => InvoiceItem::TYPE_SERVICE,
                    'description' => sprintf(
                        '%s (%s - %s)',
                        $service->description(),
                        $start->format('m/d/Y'),
                        $end->format('m/d/Y'),
                    ),
                    'amount' => $service->recurring_amount,
                    'taxable' => $service->product->taxable,
                    'period_start' => $start,
                    'period_end' => $end,
                ]);
            }

            $invoice->recalculate();
            Activity::record("Generated invoice #{$invoice->number} for ".Money::format($invoice->total, $invoice->currency), $invoice);

            return $invoice;
        });
    }
}
