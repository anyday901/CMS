<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Creates new services for a client, with their first invoice. */
class OrderService
{
    public function __construct(private CreditApplier $credit) {}

    /**
     * With $invoice true the service starts pending and activates when the
     * first invoice is paid. With $invoice false it starts active and the
     * renewal run invoices it from $start.
     *
     * @return array{service: Service, invoice: ?Invoice}
     */
    public function create(
        Client $client,
        Product $product,
        BillingCycle $cycle,
        CarbonInterface $start,
        ?string $label = null,
        bool $invoice = true,
    ): array {
        $price = $product->priceFor($cycle, $client->currency)
            ?? throw new InvalidArgumentException("{$product->name} has no {$cycle->label()} price in {$client->currency}.");

        $order = DB::transaction(function () use ($client, $product, $cycle, $start, $label, $invoice, $price) {
            $service = Service::create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'label' => $label,
                'billing_cycle' => $cycle,
                'recurring_amount' => $price->price,
                'status' => $invoice ? ServiceStatus::Pending : ServiceStatus::Active,
                'registration_date' => $start,
                'next_due_date' => $start,
            ]);
            Activity::record("Ordered {$service->description()} ({$cycle->label()})", $service);

            if (! $invoice) {
                return ['service' => $service, 'invoice' => null];
            }

            $first = Invoice::create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => today(),
                'due_date' => $start,
            ]);

            $end = $cycle->isRecurring() ? $cycle->advance($start)->subDay() : null;

            $first->items()->create([
                'service_id' => $service->id,
                'type' => InvoiceItem::TYPE_SERVICE,
                'description' => $end
                    ? sprintf('%s (%s - %s)', $service->description(), $start->format('m/d/Y'), $end->format('m/d/Y'))
                    : $service->description(),
                'amount' => $price->price,
                'taxable' => $product->taxable,
                'period_start' => $start,
                'period_end' => $end,
            ]);

            if ($price->setup_fee > 0) {
                $first->items()->create([
                    'service_id' => $service->id,
                    'type' => InvoiceItem::TYPE_SETUP_FEE,
                    'description' => "Setup fee: {$service->description()}",
                    'amount' => $price->setup_fee,
                    'taxable' => $product->taxable,
                ]);
            }

            return ['service' => $service, 'invoice' => $first->recalculate()];
        });

        $this->credit->applyIfEnabled($order['invoice']);
        $order['invoice']?->refresh();
        $order['service']->refresh();

        return $order;
    }
}
