<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A promotion code: a percent or fixed amount off the price of the products
 * it covers, on the first payment only or on every renewal too. Setup fees
 * aren't discounted.
 */
class Promotion extends Model
{
    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const TYPES = [self::PERCENT => 'Percent', self::FIXED => 'Fixed amount'];

    protected $fillable = [
        'code', 'type', 'value', 'recurring', 'product_ids', 'starts_on', 'expires_on',
        'max_uses', 'once_per_client', 'active', 'notes',
    ];

    protected $attributes = ['type' => self::PERCENT, 'uses' => 0, 'recurring' => false, 'once_per_client' => false, 'active' => true];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'recurring' => 'boolean',
            'product_ids' => 'array',
            'starts_on' => 'date',
            'expires_on' => 'date',
            'max_uses' => 'integer',
            'uses' => 'integer',
            'once_per_client' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** Codes are matched without regard to case or surrounding spaces. */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', strtoupper(trim($code)))->first();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function appliesTo(Product $product): bool
    {
        return empty($this->product_ids) || in_array($product->id, array_map('intval', $this->product_ids), true);
    }

    /** The amount off a price, never more than the price. Percent rounds half up. */
    public function discountOn(int $amount): int
    {
        if ($amount <= 0) {
            return 0;
        }

        $discount = $this->type === self::PERCENT
            ? intdiv($amount * $this->value + 5000, 10000)
            : $this->value;

        return min($discount, $amount);
    }

    /**
     * Why the code can't be used right now, or null when it can. Fixed
     * amounts are set in the billing currency, so they only work there.
     */
    public function problem(?Client $client, string $currency, CarbonInterface $today): ?string
    {
        return match (true) {
            ! $this->active,
            $this->starts_on !== null && $today->lt($this->starts_on) => "The code {$this->code} isn't valid.",
            $this->expires_on !== null && $today->gt($this->expires_on) => "The code {$this->code} has expired.",
            $this->max_uses !== null && $this->uses >= $this->max_uses => "The code {$this->code} has been used up.",
            $this->type === self::FIXED && $currency !== config('billing.currency') => "The code {$this->code} can't be used with {$currency} prices.",
            $this->once_per_client && $client !== null && $this->orders()->where('client_id', $client->id)->exists() => "You've already used the code {$this->code}.",
            default => null,
        };
    }

    /** "10%" or "$5.00" */
    public function valueLabel(): string
    {
        return $this->type === self::PERCENT
            ? TaxRule::formatRate($this->value)
            : Money::format($this->value, config('billing.currency'));
    }

    /** "10% off the first payment" or "$5.00 off every payment" */
    public function summary(): string
    {
        return $this->valueLabel().' off '.($this->recurring ? 'every payment' : 'the first payment');
    }
}
