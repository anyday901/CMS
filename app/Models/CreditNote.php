<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money given back against a paid or part paid invoice, for some or all of
 * its lines. The tax rate and whether prices include tax come from the
 * invoice, so the credit matches what was charged. The money goes back as
 * refunds of the invoice's payments, to the client's credit balance or the
 * way they paid, and those refunds point back here.
 */
class CreditNote extends Model
{
    /** How the money goes back, as RefundService's refund modes. */
    public const MODES = ['credit', 'gateway', 'manual'];

    protected $fillable = [
        'number', 'client_id', 'invoice_id', 'currency', 'subtotal', 'tax', 'tax_name', 'tax_rate', 'tax_inclusive', 'total',
        'reason', 'refund_mode', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
            'subtotal' => 'integer',
            'tax' => 'integer',
            'tax_rate' => 'integer',
            'total' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    /** The staff member who issued it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The refunds that gave the money back. */
    public function refunds(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Whether its refund failed at the gateway, so it no longer counts. */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** "Sales tax (8.25%)" */
    public function taxLabel(): string
    {
        return ($this->tax_name ?? 'Tax').($this->tax_rate ? ' ('.TaxRule::formatRate($this->tax_rate).')' : '');
    }

    /** How the money went back, for staff. */
    public function modeLabel(): string
    {
        return match ($this->refund_mode) {
            'credit' => 'Added to account credit',
            'gateway' => 'Refunded through the gateway',
            default => 'Refunded outside the app',
        };
    }
}
