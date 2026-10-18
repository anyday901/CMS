<?php

namespace App\Ordering;

use App\Billing\OrderChoices;
use App\Billing\OrderLine;
use App\Billing\OrderService;
use App\Domains\DomainLine;
use App\Domains\DomainOrders;
use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Events\OrderPlaced;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Prices the cart and turns it into an order with one invoice. */
class Checkout
{
    public function __construct(private OrderService $orders, private DomainOrders $domainOrders) {}

    /** Where a guest's choice of currency in the store is kept. */
    public const SESSION_CURRENCY = 'store_currency';

    /**
     * The currency a client is billed in. A guest sees the store in the
     * currency they picked, when it's still one of the currencies, and
     * signs up in it.
     */
    public static function currencyFor(?Client $client): string
    {
        if ($client !== null) {
            return $client->currency ?? config('billing.currency');
        }

        $chosen = request()->hasSession() ? request()->session()->get(self::SESSION_CURRENCY) : null;

        return is_string($chosen) && $chosen !== config('billing.currency') && Currency::known($chosen) ? $chosen : config('billing.currency');
    }

    public function quote(Cart $cart, ?Client $client): CartQuote
    {
        $currency = self::currencyFor($client);
        ['lines' => $lines, 'keys' => $keys, 'errors' => $errors] = $this->resolve($cart, $client);
        ['domains' => $domains, 'errors' => $domainErrors] = $this->resolveDomains($cart, $currency);

        $promotion = null;
        $promotionError = null;
        if ($cart->promotionCode() !== null) {
            $promotion = Promotion::findByCode($cart->promotionCode());
            $promotionError = $this->promotionProblem($promotion, $cart->promotionCode(), $lines, $client, $currency);
            $promotion = $promotionError === null ? $promotion : null;
        }

        $rows = [];
        $trialsTaken = [];
        foreach ($lines as $i => $line) {
            $priced = $this->orders->price($line, $currency);
            // Only the first of a product in the cart gets its trial, as when the order is placed.
            $first = $this->orders->firstPeriod($priced, today(), $client, $line->parentLine !== null ? $lines[$line->parentLine] : null, $trialsTaken);
            if ($first->trial) {
                $trialsTaken[] = $line->product->id;
            }
            $discount = $promotion?->appliesTo($line->product) ? $promotion->discountOn($first->amount) : 0;
            $rows[] = [
                'key' => $keys[$i],
                'priced' => $priced,
                'first' => $first,
                'discount' => $discount,
                // Taken from what the promotion leaves, as on the invoice.
                'group_discount' => $client?->group?->discountOn($first->amount - $discount) ?? 0,
                'addon' => $line->parentLine !== null || $line->parentService !== null,
            ];
        }

        return new CartQuote($currency, $rows, $errors + $domainErrors, $promotion, $promotionError, $domains, $client?->group);
    }

    /**
     * Places the order: one service per product and add-on, and one pending
     * domain per domain, all on one invoice, with the promotion code's use counted.
     *
     * @return array{order: Order, services: list<Service>}
     *
     * @throws InvalidArgumentException when the cart can't be ordered as it is.
     */
    public function place(Cart $cart, Client $client, ?string $ip = null, bool $acceptedTerms = false): array
    {
        ['lines' => $lines, 'errors' => $errors] = $this->resolve($cart, $client);
        ['domains' => $domains, 'errors' => $domainErrors] = $this->resolveDomains($cart, $client->currency);
        $errors += $domainErrors;

        if ($errors !== []) {
            throw new InvalidArgumentException(reset($errors));
        }
        if ($lines === [] && $domains === []) {
            throw new InvalidArgumentException('Your cart is empty.');
        }

        $result = DB::transaction(function () use ($cart, $client, $lines, $domains, $ip, $acceptedTerms) {
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
            $this->takeStock($lines);

            $order = Order::create([
                'client_id' => $client->id,
                'promotion_id' => $promotion?->id,
                'promotion_code' => $promotion?->code,
                'ip_address' => $ip,
                'terms_accepted_at' => $acceptedTerms ? now() : null,
            ]);
            $placed = $this->orders->place($client, $lines, today(), true, $promotion, $order, array_values($domains));
            Activity::record("Placed order #{$order->id}", $order, array_filter(['promotion_code' => $promotion?->code]));

            return ['order' => $order, 'services' => $placed['services']];
        });

        $cart->clear();
        OrderPlaced::dispatch($result['order']);

        return $result;
    }

    /**
     * Counts the order's products out of stock, locked so two checkouts
     * can't both take the last one.
     *
     * @param  list<OrderLine>  $lines
     *
     * @throws InvalidArgumentException when there isn't enough left.
     */
    private function takeStock(array $lines): void
    {
        $wanted = array_count_values(array_map(fn (OrderLine $line) => $line->product->id, $lines));
        $products = Product::whereKey(array_keys($wanted))->where('stock_control', true)->lockForUpdate()->get();

        foreach ($products as $product) {
            if ($problem = self::stockProblem($product, $wanted[$product->id])) {
                throw new InvalidArgumentException($problem);
            }
            $product->decrement('stock_quantity', $wanted[$product->id]);
        }
    }

    /** Why $quantity of a product can't be ordered, or null when they can. */
    public static function stockProblem(Product $product, int $quantity = 1): ?string
    {
        return match (true) {
            $product->hasStock($quantity) => null,
            $product->stock_quantity > 0 => "Only {$product->stock_quantity} of {$product->name} left in stock.",
            default => "{$product->name} is out of stock.",
        };
    }

    /**
     * Prices the cart's domains. A domain that can't be ordered now is left
     * out with an error under its cart key.
     *
     * @return array{domains: array<string, DomainLine>, errors: array<string, string>}
     */
    public function resolveDomains(Cart $cart, string $currency): array
    {
        $domains = [];
        $errors = [];

        foreach ($cart->domains() as $key => $data) {
            try {
                $domains[$key] = $this->domainOrders->line($data, $currency);
            } catch (InvalidArgumentException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        return ['domains' => $domains, 'errors' => $errors];
    }

    /** @param  list<OrderLine>  $lines */
    private function promotionProblem(?Promotion $promotion, string $code, array $lines, ?Client $client, string $currency): ?string
    {
        if ($promotion === null) {
            return "The code {$code} isn't valid.";
        }

        $applies = collect($lines)->contains(fn (OrderLine $line) => $promotion->appliesTo($line->product));

        return $promotion->problem($client, $currency, today())
            ?? ($applies ? null : "The code {$code} doesn't apply to anything in your cart.");
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
        $lines = [];
        $keys = [];
        $errors = [];

        $addonsForServices = [];
        $taken = [];

        foreach ($cart->lines() as $key => $data) {
            try {
                $resolved = $this->check($data, $client, count($lines));
                if ($data['parent_service_id'] ?? null) {
                    $pair = $data['parent_service_id'].'-'.$data['product_id'];
                    if (isset($addonsForServices[$pair])) {
                        throw new InvalidArgumentException("{$resolved[0]->product->name} is already in your cart for {$resolved[0]->parentService->description()}.");
                    }
                    $addonsForServices[$pair] = true;
                }
                $taken = $this->countStock($resolved, $taken);
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
     * Adds a cart line's products to the stock the cart takes so far.
     *
     * @param  list<OrderLine>  $lines
     * @param  array<int, int>  $taken  Quantities by product id.
     * @return array<int, int>
     *
     * @throws InvalidArgumentException when there isn't enough stock for them too.
     */
    private function countStock(array $lines, array $taken): array
    {
        foreach ($lines as $line) {
            $id = $line->product->id;
            $taken[$id] = ($taken[$id] ?? 0) + 1;

            if ($problem = self::stockProblem($line->product, $taken[$id])) {
                throw new InvalidArgumentException($problem);
            }
        }

        return $taken;
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
        $this->countStock($lines, []);

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

        if ($service->addons()->where('product_id', $addon->id)->whereIn('status', [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended])->exists()) {
            throw new InvalidArgumentException("{$service->description()} already has {$addon->name}.");
        }
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
