<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of clients, such as resellers or VIPs, shown as a coloured label
 * and used to filter the clients list. A group can give its clients a
 * percent off their services and its own billing settings, which override
 * the global ones, and a default credit limit.
 */
class ClientGroup extends Model
{
    /** Colours staff can pick, as a hex code and a name. */
    public const COLOURS = [
        '#64748b' => 'Grey',
        '#0d9488' => 'Teal',
        '#2563eb' => 'Blue',
        '#7c3aed' => 'Purple',
        '#db2777' => 'Pink',
        '#dc2626' => 'Red',
        '#ea580c' => 'Orange',
        '#ca8a04' => 'Gold',
        '#16a34a' => 'Green',
    ];

    protected $fillable = [
        'name', 'colour', 'description', 'discount_percent', 'discount_renewals',
        'exempt_late_fees', 'exempt_suspension', 'separate_invoices', 'credit_limit',
    ];

    protected $attributes = [
        'discount_percent' => 0,
        'discount_renewals' => false,
        'exempt_late_fees' => false,
        'exempt_suspension' => false,
        'separate_invoices' => false,
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'integer',
            'discount_renewals' => 'boolean',
            'exempt_late_fees' => 'boolean',
            'exempt_suspension' => 'boolean',
            'separate_invoices' => 'boolean',
            'credit_limit' => 'integer',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * The group's discount on a price, never more than the price. In
     * hundredths of a percent, rounded half up, as promotion codes work.
     */
    public function discountOn(int $amount): int
    {
        if ($amount <= 0 || $this->discount_percent <= 0) {
            return 0;
        }

        return min(intdiv($amount * $this->discount_percent + 5000, 10000), $amount);
    }

    /** "10%" */
    public function discountLabel(): string
    {
        return TaxRule::formatRate($this->discount_percent);
    }

    /** What the group changes, for the groups list: "10% off new orders and renewals, no late fees". */
    public function settingsSummary(): string
    {
        return implode(', ', array_filter([
            $this->discount_percent > 0 ? $this->discountLabel().' off new orders'.($this->discount_renewals ? ' and renewals' : '') : null,
            $this->exempt_late_fees ? 'no late fees' : null,
            $this->exempt_suspension ? 'no overdue suspension or termination' : null,
            $this->separate_invoices ? 'separate invoices' : null,
            $this->credit_limit !== null ? Money::format($this->credit_limit, config('billing.currency')).' credit limit' : null,
        ]));
    }
}
