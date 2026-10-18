<?php

namespace App\Models;

use App\Enums\QuoteStage;
use App\Models\Concerns\HasTaxLevels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced proposal for a client. Tax is fixed when the quote is made, the
 * same way as an invoice's, and carried over to the invoice it becomes, so
 * the client pays the total they accepted.
 */
class Quote extends Model
{
    use HasTaxLevels;

    /** Days a new quote stays open for. */
    public const VALID_DAYS = 30;

    protected $fillable = [
        'client_id', 'subject', 'stage', 'currency', 'issue_date', 'valid_until', 'notes', 'admin_notes',
    ];

    protected $attributes = [
        'stage' => 'draft',
        'subtotal' => 0,
        'tax' => 0,
        'tax_rate' => 0,
        'total' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            if (! $quote->isDirty('tax_inclusive')) {
                $quote->tax_inclusive = (bool) config('billing.tax_inclusive');
            }
        });

        static::created(function (Quote $quote) {
            if ($quote->number === null) {
                $quote->forceFill(['number' => (string) $quote->id])->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'stage' => QuoteStage::class,
            'tax_inclusive' => 'boolean',
            'issue_date' => 'date',
            'valid_until' => 'date',
            'delivered_at' => 'datetime',
            'accepted_at' => 'datetime',
            'subtotal' => 'integer',
            'tax' => 'integer',
            'tax_rate' => 'integer',
            'total' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /** The invoice the quote became once accepted. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Quotes the client can see in the portal. */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->whereIn('stage', QuoteStage::VISIBLE);
    }

    /**
     * Recompute subtotal, tax and total from the lines, the same way as
     * Invoice::recalculate(): with tax-inclusive pricing the lines already
     * contain the tax.
     */
    public function recalculate(): static
    {
        $taxable = (int) $this->items()->where('taxable', true)->sum('amount');
        $this->subtotal = (int) $this->items()->sum('amount');
        $this->applyTax($taxable);
        $this->save();

        return $this;
    }

    /** Past its valid until date. */
    public function isExpired(): bool
    {
        return $this->valid_until->lt(today());
    }

    /** Lines can change until the quote has become an invoice. */
    public function isEditable(): bool
    {
        return $this->invoice_id === null;
    }

    /** Sent to the client and waiting on their answer, whether or not it has expired. */
    public function awaitsClient(): bool
    {
        return in_array($this->stage, [QuoteStage::Delivered, QuoteStage::OnHold], true) && $this->invoice_id === null;
    }
}
