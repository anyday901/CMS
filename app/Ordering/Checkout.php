<?php

namespace App\Ordering;

use App\Billing\OrderChoices;
use App\Billing\OrderLine;
use App\Billing\OrderService;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Prices the cart and turns it into an order with one invoice. */
class Checkout
{
    public function __construct(private OrderService $orders) {}

    public static function currencyFor(?Client $client): string
    {
        return $client?->currency ?? config('billing.currency');
    }

    public function quote(Cart $cart, ?Client $client): CartQuote
    {
        $currency = self::currencyFor($client);
        ['lines' => $lines, 'keys' => $keys, 'errors' => $errors] = $this->resolve($cart, $client);

        $promotion = null;
        $promotionError = null;
        if ($cart->promotionCode() !== null) {
            $promotion = Promotion::findByCode($cart->promotionCode());
            $promotionError = $this->promotionProblem($promotion, $cart->promotionCode(), $lines, $client, $currency);
            $promotion = $promotionError === null ? $promotion : null;
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $priced = $this->orders->price($line, $currency);
            $rows[] = [
                'key' => $keys[$i],
                'priced' => $priced,
                'discount' => $promotion?->appliesTo($line->product) ? $promotion->discountOn($priced->recurring) : 0,
                'addon' => $line->parentLine !== null || $line->parentService !== null,
            ];
        }

        return new CartQuote($currency, $rows, $errors, $promotion, $promotionError);
    }

    /**
     * Places the order: one service per product and add-on, all on one
     * invoice, with the promotion code's use counted.
     *
     * @return array{order: Order, services: list<Service>}
     *
     * @throws InvalidArgumentException when the cart can't be ordered as it is.
     */
    public function place(Cart $cart, Client $client, ?string $ip = null): array
    {
        ['lines' => $lines, 'errors' => $errors] = $this->resolve($cart, $client);

        if ($errors !== []) {
            throw new InvalidArgumentException(reset($errors));
        }
        if ($lines === []) {
            throw new InvalidArgumentException('Your cart is empty.');
        }

        $result = DB::transaction(function () use ($cart, $client, $lines, $ip) {
            $promotion = null;
            if ($cart->promotionCode() !== null) {
                // Locked so two checkouts can't both take the last use.
                $promotion = Promotion::where('code', $cart->promotionCode())->lockForUpdate()->first();
                $problem = $this->promotionProblem($promotion, $cart->promotionCode(), $lines, $client, $client->currency);
                if ($problem !== null) {
                    throw new InvalidArgumentException($problem);
                }
                $promotion->increment('uses');
            }

            $order = Order::create([
                'client_id' => $client->id,
                'promotion_id' => $promotion?->id,
                'promotion_code' => $promotion?->code,
                'ip_address' => $ip,
            ]);
            $placed = $this->orders->place($client, $lines, today(), true, $promotion, $order);
            $order->update(['invoice_id' => $placed['invoice']->id]);
            Activity::record("Placed order #{$order->id}", $order, array_filter(['promotion_code' => $promotion?->code]));

            return ['order' => $order, 'services' => $placed['services']];
        });

        $cart->clear();

        return $result;
    }

    /** @param  list<OrderLine>  $lines */
    private function promotionProblem(?Promotion $promotion, string $code, array $lines, ?Client $client, string $currency): ?string
    {
        if ($promotion === null) {
            return "The code {$code} isn't valid.";
        }

        if ($problem = $promotion->problem($client, $currency, today())) {
            return $problem;
        }

        foreach ($lines as $line) {
            if ($promotion->appliesTo($line->product)) {
                return null;
            }
        }

        return "The code {$code} doesn't apply to anything in your cart.";
    }

    /**
     * Turns the cart into order lines, checking each product can still be
     * ordered. A line that can't (and its add-ons) is left out with an error
     * under its cart key.
     *
     * @return array{lines: list<OrderLine>, keys: list<string>, errors: array<string, string>}
     */
    public function resolve(Cart $cart, ?Client $client): array
    {
        $currency = self::currencyFor($client);
        $lines = [];
        $keys = [];
        $errors = [];

        foreach ($cart->lines() as $key => $data) {
            try {
                $resolved = $this->check($data, $client, count($lines));
            } catch (InvalidArgumentException $e) {
                $errors[$key] = $e->getMessage();

                continue;
            }

            foreach ($resolved as $line) {
                $lines[] = $line;
                $keys[] = $key;
            }
        }

        return ['lines' => $lines, 'keys' => $keys, 'errors' => $errors];
    }

    /**
     * Checks one cart line can be ordered, before it goes in the cart and
     * again at checkout. $offset is where its product sits in the order.
     *
     * @param  array<string, mixed>  $data
     * @return list<OrderLine>
     *
     * @throws InvalidArgumentException
     */
    public function check(array $data, ?Client $client, int $offset = 0): array
    {
        $currency = self::currencyFor($client);
        $lines = $data['parent_service_id'] ?? null
            ? [$this->addonForService($data, $client, $currency)]
            : $this->productWithAddons($data, $offset, $currency);

        foreach ($lines as $line) {
            $this->orders->price($line, $currency);
        }

        return $lines;
    }

    /** @return list<OrderLine> The product first, then its add-ons. */
    private function productWithAddons(array $data, int $offset, string $currency): array
    {
        $product = Product::orderable()->with('addons')->find($data['product_id'] ?? null)
            ?? throw new InvalidArgumentException('A product in your cart is no longer available. Remove it to carry on.');
        $cycle = BillingCycle::tryFrom((string) ($data['billing_cycle'] ?? ''))
            ?? throw new InvalidArgumentException("Choose a billing cycle for {$product->name}.");

        $lines = [new OrderLine($product, $cycle, new OrderChoices((array) ($data['options'] ?? []), $data['custom_fields'] ?? null), $data['label'] ?? null)];

        foreach (array_unique(array_map('intval', (array) ($data['addons'] ?? []))) as $addonId) {
            $addon = $this->addonProduct($product, $addonId);
            $addonCycle = $addon->addonCycleFor($cycle, $currency)
                ?? throw new InvalidArgumentException("{$addon->name} isn't sold with a {$cycle->label()} {$product->name}.");
            $lines[] = new OrderLine($addon, $addonCycle, parentLine: $offset);
        }

        return $lines;
    }

    private function addonForService(array $data, ?Client $client, string $currency): OrderLine
    {
        $service = $client?->services()->with('product.addons')->find($data['parent_service_id'])
            ?? throw new InvalidArgumentException('Log in to order add-ons for your services.');

        if ($service->status !== ServiceStatus::Active) {
            throw new InvalidArgumentException("Add-ons can only be added to active services, and {$service->description()} is {$service->status->value}.");
        }

        $addon = $this->addonProduct($service->product, (int) ($data['product_id'] ?? 0));
        $cycle = $addon->addonCycleFor($service->billing_cycle, $currency)
            ?? throw new InvalidArgumentException("{$addon->name} isn't sold for {$service->description()}.");

        return new OrderLine($addon, $cycle, parentService: $service);
    }

    private function addonProduct(Product $product, int $addonId): Product
    {
        $addon = $product->addons->firstWhere('id', $addonId);

        if ($addon === null || ! $addon->active) {
            throw new InvalidArgumentException("An add-on for {$product->name} is no longer available. Remove it to carry on.");
        }

        return $addon;
    }
}
