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
        'tax_rate' => 0,
        'total' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice) {
            if (! $invoice->isDirty('tax_rate') && $invoice->client) {
                $rule = TaxRule::forClient($invoice->client);
                $invoice->tax_name = $rule?->name;
                $invoice->tax_rate = $rule?->rate ?? 0;
            }
        });

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
        return $this->hasMany(InvoiceItem::class);
    }

    /** "Sales tax (8.25%)" */
    public function taxLabel(): string
    {
        return ($this->tax_name ?? 'Tax').($this->tax_rate ? ' ('.TaxRule::formatRate($this->tax_rate).')' : '');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Recompute subtotal, tax and total from the line items. */
    public function recalculate(): static
    {
        $this->subtotal = (int) $this->items()->sum('amount');
        $this->tax = TaxRule::taxOn((int) $this->items()->where('taxable', true)->sum('amount'), $this->tax_rate);
        $this->total = $this->subtotal + $this->tax;
        $this->save();

        return $this;
    }

    /** Drafts and unpaid invoices can still have their lines changed. */
    public function isEditable(): bool
    {
        return in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true);
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
