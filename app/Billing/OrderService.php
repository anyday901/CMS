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
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Creates new services for a client, with their first invoice. */
class OrderService
{
    public function __construct(
        private CreditApplier $credit,
        private ConfigurableOptions $options,
        private PaymentRecorder $payments,
    ) {}

    /**
     * One service, as staff add it. See place().
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
        $order = $this->place($client, [new OrderLine($product, $cycle, $choices, $label)], $start, $invoice);

        return ['service' => $order['services'][0], 'invoice' => $order['invoice']];
    }

    /**
     * Creates a service for each line, all on one first invoice. With
     * $invoice true the services start pending and activate when that
     * invoice is paid (at once when there is nothing to pay). With $invoice
     * false they start active and the renewal run invoices them from $start.
     *
     * @param  list<OrderLine>  $lines
     * @return array{services: list<Service>, invoice: ?Invoice}
     */
    public function place(
        Client $client,
        array $lines,
        CarbonInterface $start,
        bool $invoice = true,
        ?Promotion $promotion = null,
        ?Order $order = null,
    ): array {
        $priced = [];
        foreach (array_values($lines) as $i => $line) {
            if ($line->parentLine !== null && ($line->parentLine >= $i || $line->parentLine < 0)) {
                throw new InvalidArgumentException('An add-on must come after the service it is for.');
            }
            $priced[] = $this->price($line, $client->currency);
        }

        $result = DB::transaction(function () use ($client, $priced, $start, $invoice, $promotion, $order) {
            $first = $invoice ? Invoice::create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => today(),
                'due_date' => $start,
            ]) : null;

            $services = [];
            foreach ($priced as $line) {
                $parent = $line->line->parentLine !== null ? $priced[$line->line->parentLine]->line : null;
                $period = $first !== null ? $this->firstPeriod($line, $start, $client, $parent) : null;
                $services[] = $this->createService($client, $line, $services, $start, $promotion, $order, $first, $period);
            }

            return ['services' => $services, 'invoice' => $first?->recalculate()];
        });

        $this->credit->applyIfEnabled($result['invoice']);

        if ($result['invoice']) {
            $this->payments->settleIfNothingDue($result['invoice']);
            $result['invoice']->refresh();
            InvoiceIssued::dispatch($result['invoice']);
        }

        foreach ($result['services'] as $service) {
            $service->refresh();

            if (! $invoice) {
                // Added as active without an invoice, so nothing else will activate it.
                ServiceActivated::dispatch($service);
            }
        }

        return $result;
    }

    /**
     * Checks a line can be ordered in this currency and prices it.
     *
     * @throws InvalidArgumentException when it can't be ordered.
     */
    public function price(OrderLine $line, string $currency): PricedLine
    {
        $product = $line->product;
        $price = $product->priceFor($line->cycle, $currency)
            ?? throw new InvalidArgumentException("{$product->name} has no {$line->cycle->label()} price in {$currency}.");
        $selected = $this->options->select($product, $line->choices->options, $line->cycle);
        $this->options->assertCurrency($selected, $currency);

        return new PricedLine(
            $line,
            $selected,
            $price->price + $this->options->recurring($selected),
            $price->setup_fee + $this->options->setupFee($selected),
        );
    }

    /**
     * What the first invoice charges for a line: a free trial when the product
     * has one and the client hasn't had it before, a part period up to the
     * product's billing day when it has one, or a whole cycle. An add-on with
     * no billing day of its own follows the one of the product it's ordered
     * with, when it's billed on the same cycle.
     */
    public function firstPeriod(PricedLine $priced, CarbonInterface $start, ?Client $client, ?OrderLine $parent = null): FirstPeriod
    {
        $product = $priced->line->product;
        $billedBy = $product->prorata_day === null && $parent?->cycle === $priced->line->cycle ? $parent->product : $product;
        $trial = $product->trial_days > 0 && ! $client?->services()->where('product_id', $product->id)->exists();

        return FirstPeriod::for(
            $priced->line->cycle,
            $priced->recurring,
            $start,
            $trial ? $product->trial_days : null,
            $billedBy->prorata_day,
            $billedBy->prorata_extend_day,
        );
    }

    /**
     * @param  list<Service>  $created  Services made earlier in this order.
     * @param  Invoice|null  $first  The order's invoice; null when it isn't invoiced and starts active.
     * @param  FirstPeriod|null  $period  What $first charges for the service.
     */
    private function createService(
        Client $client,
        PricedLine $priced,
        array $created,
        CarbonInterface $start,
        ?Promotion $promotion,
        ?Order $order,
        ?Invoice $first,
        ?FirstPeriod $period,
    ): Service {
        $line = $priced->line;
        $discount = $promotion?->appliesTo($line->product) ? $promotion->discountOn($priced->recurring) : 0;

        $service = Service::create([
            'client_id' => $client->id,
            'product_id' => $line->product->id,
            'order_id' => $order?->id,
            'parent_service_id' => $line->parentService?->id ?? ($line->parentLine !== null ? $created[$line->parentLine]->id : null),
            'label' => $line->label,
            'billing_cycle' => $line->cycle,
            'recurring_amount' => $priced->recurring,
            'promotion_id' => $discount > 0 ? $promotion->id : null,
            'recurring_discount' => $discount > 0 && $promotion->recurring && $line->cycle->isRecurring() ? $discount : 0,
            'status' => $first !== null ? ServiceStatus::Pending : ServiceStatus::Active,
            'registration_date' => $start,
            'next_due_date' => $start,
            'trial_ends_on' => $period?->trial ? $period->nextDue() : null,
            'custom_fields' => $line->choices->customFields,
        ]);
        $this->options->save($service, $priced->options);
        Activity::record("Ordered {$service->description()} ({$line->cycle->label()})", $service);

        if ($first === null || $period === null) {
            return $service;
        }

        $taxable = $line->product->taxable;
        $description = InvoiceItem::serviceDescription($service, $period->start, $period->end);

        $first->items()->create([
            'service_id' => $service->id,
            'type' => InvoiceItem::TYPE_SERVICE,
            'description' => match (true) {
                $period->trial => Str::limit("Free trial: {$description}", 255, ''),
                $period->prorated => Str::limit("Prorated: {$description}", 255, ''),
                default => $description,
            },
            'amount' => $period->amount,
            'taxable' => $taxable,
            'period_start' => $period->start,
            'period_end' => $period->end,
        ]);

        $firstDiscount = $discount > 0 ? $promotion->discountOn($period->amount) : 0;
        if ($firstDiscount > 0) {
            $first->items()->create([
                'service_id' => $service->id,
                'type' => InvoiceItem::TYPE_PROMOTION,
                'description' => InvoiceItem::promotionDescription($service, $promotion->code),
                'amount' => -$firstDiscount,
                'taxable' => $taxable,
            ]);
        }

        if ($priced->setupFee > 0) {
            $first->items()->create([
                'service_id' => $service->id,
                'type' => InvoiceItem::TYPE_SETUP_FEE,
                'description' => "Setup fee: {$service->description()}",
                'amount' => $priced->setupFee,
                'taxable' => $taxable,
            ]);
        }

        return $service;
    }
}
