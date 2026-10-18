<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A card or other method a client saved with a gateway. Only the gateway's
 * id for it and what to show are kept; the gateway holds the details.
 */
class PaymentMethod extends Model
{
    protected $fillable = [
        'client_id', 'gateway', 'gateway_customer_id', 'gateway_reference', 'type',
        'brand', 'last4', 'exp_month', 'exp_year', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** "Visa ending 4242", or the type when there's nothing more to show. */
    public function label(): string
    {
        $name = match ($this->brand) {
            null, '' => ucfirst(str_replace('_', ' ', $this->type)),
            'amex' => 'American Express',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
            default => ucwords(str_replace('_', ' ', $this->brand)),
        };

        return $this->last4 ? "{$name} ending {$this->last4}" : $name;
    }

    /** "04/2027", or null for methods without an expiry date. */
    public function expiry(): ?string
    {
        return $this->exp_month && $this->exp_year ? sprintf('%02d/%d', $this->exp_month, $this->exp_year) : null;
    }

    public function isExpired(?Carbon $on = null): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        return ($on ?? today())->gt(Carbon::create($this->exp_year, $this->exp_month, 1)->endOfMonth());
    }

    /** Makes this the client's default method, the one charged automatically. */
    public function makeDefault(): void
    {
        static::where('client_id', $this->client_id)->whereKeyNot($this->id)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }
}
