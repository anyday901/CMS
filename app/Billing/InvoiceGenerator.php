<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
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
    /** @return Collection<int, Invoice> */
    public function generate(CarbonInterface $today): Collection
    {
        $cutoff = $today->copy()->addDays((int) config('billing.invoice_days_before_due'));

        $services = Service::query()
            ->with(['product', 'client'])
            ->whereIn('status', ServiceStatus::billable())
            ->where('billing_cycle', '!=', BillingCycle::OneTime)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $cutoff)
            ->whereDoesntHave('invoiceItems', function (Builder $items) {
                $items->whereColumn('invoice_items.period_start', 'services.next_due_date')
                    ->whereHas('invoice', fn (Builder $invoice) => $invoice
                        ->where('status', '!=', InvoiceStatus::Cancelled));
            })
            ->orderBy('id')
            ->get();

        return $services
            ->groupBy('client_id')
            ->map(fn (Collection $clientServices) => $this->invoiceClient($clientServices, $today))
            ->values();
    }

    /** @param Collection<int, Service> $services */
    private function invoiceClient(Collection $services, CarbonInterface $today): Invoice
    {
        return DB::transaction(function () use ($services, $today) {
            $client = $services->first()->client;

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
                    'period_start' => $start,
                    'period_end' => $end,
                ]);
            }

            return $invoice->recalculate();
        });
    }
}
