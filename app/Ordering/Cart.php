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
 * Domain registrations and transfers are kept apart:
 * ['name', 'type', 'years', 'id_protection', 'epp_code', 'nameservers'].
 */
class Cart
{
    private const LINES = 'cart.lines';

    private const DOMAINS = 'cart.domains';

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

    /** @return array<string, array<string, mixed>> */
    public function domains(): array
    {
        return $this->session->get(self::DOMAINS, []);
    }

    /**
     * Adds a domain, replacing the same name already in the cart.
     *
     * @param  array<string, mixed>  $line
     */
    public function addDomain(array $line): string
    {
        $domains = array_filter($this->domains(), fn (array $domain) => $domain['name'] !== $line['name']);
        $key = 'd'.Str::lower(Str::random(11));
        $domains[$key] = $line + ['type' => 'register', 'years' => 1, 'id_protection' => false, 'epp_code' => null, 'nameservers' => []];
        $this->session->put(self::DOMAINS, $domains);

        return $key;
    }

    public function hasDomain(string $name): bool
    {
        return in_array($name, array_column($this->domains(), 'name'), true);
    }

    public function remove(string $key): void
    {
        $lines = $this->lines();
        unset($lines[$key]);
        $this->session->put(self::LINES, $lines);

        $domains = $this->domains();
        unset($domains[$key]);
        $this->session->put(self::DOMAINS, $domains);
    }

    public function isFull(): bool
    {
        return count($this->lines()) + count($this->domains()) >= self::MAX_LINES;
    }

    /** Products, add-ons and domains in the cart, for the cart link. */
    public function count(): int
    {
        return array_sum(array_map(fn (array $line) => 1 + count($line['addons'] ?? []), $this->lines())) + count($this->domains());
    }

    public function isEmpty(): bool
    {
        return $this->lines() === [] && $this->domains() === [];
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
        $this->session->forget([self::LINES, self::DOMAINS, self::PROMOTION]);
    }
}
