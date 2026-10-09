<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'status', 'currency', 'issue_date', 'due_date', 'notes',
    ];

    protected $attributes = [
        'status' => 'unpaid',
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
    ];

    protected static function booted(): void
    {
        static::created(function (Invoice $invoice) {
            if ($invoice->number === null) {
                $invoice->forceFill(['number' => (string) $invoice->id])->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Recompute subtotal and total from the line items. */
    public function recalculate(): static
    {
        $this->subtotal = (int) $this->items()->sum('amount');
        $this->total = $this->subtotal + $this->tax;
        $this->save();

        return $this;
    }

    public function amountPaid(): int
    {
        return (int) $this->transactions()->sum('amount');
    }

    public function balance(): int
    {
        return $this->total - $this->amountPaid();
    }
}
