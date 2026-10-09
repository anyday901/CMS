<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'invoice_id', 'gateway', 'payment_method', 'gateway_reference', 'currency',
        'amount', 'fee',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
        ];
    }

    /** Display name, e.g. "Venmo" for a Venmo payment through PayPal. */
    public function methodLabel(): string
    {
        return match ($this->payment_method ?? $this->gateway) {
            'paypal' => 'PayPal',
            'venmo' => 'Venmo',
            'cashapp' => 'Cash App',
            'bank_transfer' => 'Bank transfer',
            default => ucfirst(str_replace('_', ' ', $this->payment_method ?? $this->gateway)),
        };
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
