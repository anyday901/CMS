<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'invoice_id', 'refund_of_id', 'gateway', 'payment_method', 'gateway_reference', 'currency',
        'amount', 'fee', 'pending',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'pending' => 'boolean',
        ];
    }

    /** Display name, e.g. "Venmo" for a Venmo payment through PayPal. */
    public function methodLabel(): string
    {
        return match ($this->payment_method ?? $this->gateway) {
            'paypal' => 'PayPal',
            'venmo' => 'Venmo',
            'cashapp' => 'Cash App',
            'credit' => 'Account credit',
            'bank_transfer' => 'Bank transfer',
            default => ucfirst(str_replace('_', ' ', $this->payment_method ?? $this->gateway)),
        };
    }

    public function isRefund(): bool
    {
        return $this->amount < 0;
    }

    /** What is left to refund on a payment after earlier refunds. */
    public function refundable(): int
    {
        if ($this->isRefund()) {
            return 0;
        }

        return $this->amount + (int) $this->refunds()->sum('amount');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_id');
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
