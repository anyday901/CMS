<?php

namespace App\Ordering;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The shopping cart, kept in the session so guests can fill it before they
 * sign up. It holds only what was picked; prices are worked out again each
 * time it is shown or checked out, so they're never stale.
 *
 * A line is either a product with its add-ons, or an add-on for a service
 * the client already has:
 * ['product_id', 'billing_cycle', 'options', 'custom_fields', 'label', 'addons', 'parent_service_id'].
 */
class Cart
{
    private const LINES = 'cart.lines';

    private const PROMOTION = 'cart.promotion';

    /** Enough for any real order, and keeps a session from growing without limit. */
    public const MAX_LINES = 20;

    public function __construct(private Session $session) {}

    /** @return array<string, array<string, mixed>> */
    public function lines(): array
    {
        return $this->session->get(self::LINES, []);
    }

    /** @param  array<string, mixed>  $line */
    public function add(array $line): string
    {
        $lines = $this->lines();
        $key = Str::lower(Str::random(12));
        $lines[$key] = $line + ['options' => [], 'custom_fields' => null, 'label' => null, 'addons' => [], 'parent_service_id' => null];
        $this->session->put(self::LINES, $lines);

        return $key;
    }

    public function remove(string $key): void
    {
        $lines = $this->lines();
        unset($lines[$key]);
        $this->session->put(self::LINES, $lines);
    }

    public function isFull(): bool
    {
        return count($this->lines()) >= self::MAX_LINES;
    }

    /** Products and add-ons in the cart, for the cart link. */
    public function count(): int
    {
        return array_sum(array_map(fn (array $line) => 1 + count($line['addons'] ?? []), $this->lines()));
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [];
    }

    public function promotionCode(): ?string
    {
        return $this->session->get(self::PROMOTION);
    }

    public function setPromotionCode(?string $code): void
    {
        $code === null ? $this->session->forget(self::PROMOTION) : $this->session->put(self::PROMOTION, strtoupper(trim($code)));
    }

    public function clear(): void
    {
        $this->session->forget([self::LINES, self::PROMOTION]);
    }
}
