<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\InvoiceIssued;
use App\Events\ServiceActivated;
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
    public function __construct(private CreditApplier $credit, private ConfigurableOptions $options) {}

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
        OrderChoices $choices = new OrderChoices,
    ): array {
        $price = $product->priceFor($cycle, $client->currency)
            ?? throw new InvalidArgumentException("{$product->name} has no {$cycle->label()} price in {$client->currency}.");
        $selected = $this->options->select($product, $choices->options, $cycle);
        $this->options->assertCurrency($selected, $client);
        $recurring = $price->price + $this->options->recurring($selected);
        $setupFee = $price->setup_fee + $this->options->setupFee($selected);

        $order = DB::transaction(function () use ($client, $product, $cycle, $start, $label, $invoice, $choices, $selected, $recurring, $setupFee) {
            $service = Service::create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'label' => $label,
                'billing_cycle' => $cycle,
                'recurring_amount' => $recurring,
                'status' => $invoice ? ServiceStatus::Pending : ServiceStatus::Active,
                'registration_date' => $start,
                'next_due_date' => $start,
                'custom_fields' => $choices->customFields,
            ]);
            $this->options->save($service, $selected);
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
                'description' => InvoiceItem::serviceDescription($service, $start, $end),
                'amount' => $recurring,
                'taxable' => $product->taxable,
                'period_start' => $start,
                'period_end' => $end,
            ]);

            if ($setupFee > 0) {
                $first->items()->create([
                    'service_id' => $service->id,
                    'type' => InvoiceItem::TYPE_SETUP_FEE,
                    'description' => "Setup fee: {$service->description()}",
                    'amount' => $setupFee,
                    'taxable' => $product->taxable,
                ]);
            }

            return ['service' => $service, 'invoice' => $first->recalculate()];
        });

        $this->credit->applyIfEnabled($order['invoice']);
        $order['invoice']?->refresh();
        $order['service']->refresh();

        if ($order['invoice']) {
            InvoiceIssued::dispatch($order['invoice']);
        }

        if (! $invoice) {
            // Added as active without an invoice, so nothing else will activate it.
            ServiceActivated::dispatch($order['service']);
        }

        return $order;
    }
}
