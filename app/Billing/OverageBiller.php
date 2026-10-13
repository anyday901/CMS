<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\InvoiceIssued;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ServiceUsage;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Usage-based billing: once a month is over, invoices the disk and
 * bandwidth each service used over what its product includes, at the
 * product's price per GB, with one invoice per client. Each month of each
 * service is looked at once, so running this again bills nothing twice.
 */
class OverageBiller
{
    private const MB_PER_GB = 1024;

    public function __construct(private CreditApplier $credit) {}

    /** @return Collection<int, Invoice> the overage invoices created */
    public function run(CarbonInterface $today): Collection
    {
        return $this->unbilled($today)
            ->with('service:id,client_id')
            ->get()
            ->pluck('service.client_id')
            ->unique()
            ->map(fn (int $clientId) => $this->billClient($clientId, $today))
            ->filter()
            ->each(fn (Invoice $invoice) => $this->credit->applyIfEnabled($invoice))
            ->map(fn (Invoice $invoice) => $invoice->refresh())
            ->each(fn (Invoice $invoice) => InvoiceIssued::dispatch($invoice))
            ->values();
    }

    /** What a month's use costs over the product's allowance: [description, cents] per line. */
    public static function lines(ServiceUsage $usage, Product $product): array
    {
        if (! $product->overage_billing) {
            return [];
        }

        $month = $usage->period->format('F Y');
        $what = $usage->service->description();
        $lines = [];

        foreach ([
            'Disk' => [$usage->diskOverMb($product), $product->overage_disk_mb, $product->overage_disk_price],
            'Bandwidth' => [$usage->bandwidthOverMb($product), $product->overage_bandwidth_mb, $product->overage_bandwidth_price],
        ] as $kind => [$over, $included, $price]) {
            $amount = (int) round($over * $price / self::MB_PER_GB);

            if ($amount > 0) {
                $lines[] = [
                    "{$kind} overage for {$what}, {$month}: ".ServiceUsage::size($over).' over the '.ServiceUsage::size($included).' included, at '.Money::format($price, $usage->service->client->currency).' per GB',
                    $amount,
                ];
            }
        }

        return $lines;
    }

    private function unbilled(CarbonInterface $today)
    {
        return ServiceUsage::query()->whereNull('billed_at')->whereDate('period', '<', $today->copy()->startOfMonth());
    }

    private function billClient(int $clientId, CarbonInterface $today): ?Invoice
    {
        return DB::transaction(function () use ($clientId, $today) {
            $client = Client::lockForUpdate()->findOrFail($clientId);
            $usages = $this->unbilled($today)
                ->whereHas('service', fn ($services) => $services->where('client_id', $clientId))
                ->with(['service.product', 'service.client'])
                ->orderBy('period')->orderBy('id')
                ->get();
            // Overages are charged for services still running; ended ones just
            // aren't billed. Overage prices are in the billing currency, so
            // clients paying in another aren't charged them.
            $charged = $usages->filter(fn (ServiceUsage $usage) => $client->currency === config('billing.currency')
                && in_array($usage->service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true))
                ->map(fn (ServiceUsage $usage) => [$usage, self::lines($usage, $usage->service->product)])
                ->filter(fn (array $entry) => $entry[1] !== []);
            $invoice = null;

            if ($charged->isNotEmpty()) {
                $invoice = Invoice::create([
                    'client_id' => $client->id,
                    'status' => InvoiceStatus::Unpaid,
                    'currency' => $client->currency,
                    'issue_date' => $today,
                    'due_date' => $today->copy()->addDays((int) config('billing.overage_due_days')),
                ]);

                foreach ($charged as [$usage, $lines]) {
                    foreach ($lines as [$description, $amount]) {
                        $invoice->items()->create([
                            'service_id' => $usage->service_id,
                            'type' => InvoiceItem::TYPE_OVERAGE,
                            'description' => $description,
                            'amount' => $amount,
                            'taxable' => $usage->service->product->taxable,
                        ]);
                    }
                }

                $invoice->recalculate();
                Activity::record("Generated overage invoice #{$invoice->number} for ".Money::format($invoice->total, $invoice->currency), $invoice);
            }

            ServiceUsage::whereKey($usages->modelKeys())->update(['billed_at' => now()]);

            if ($invoice !== null) {
                ServiceUsage::whereKey($charged->map(fn (array $entry) => $entry[0]->id)->all())->update(['invoice_id' => $invoice->id]);
            }

            return $invoice;
        });
    }
}
