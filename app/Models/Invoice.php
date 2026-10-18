<?php

namespace App\Models;

use App\Billing\InvoiceNumbering;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
            if (! $invoice->isDirty('tax_inclusive')) {
                $invoice->tax_inclusive = (bool) config('billing.tax_inclusive');
            }

            if (! $invoice->isDirty('tax_rate') && $invoice->client) {
                $rule = TaxRule::forClient($invoice->client);
                $invoice->tax_name = $rule?->name;
                $invoice->tax_rate = $rule?->rate ?? 0;
            }

            // Numbered on payment instead: a proforma until then.
            if (! $invoice->isDirty('proforma') && $invoice->number === null) {
                $invoice->proforma = InvoiceNumbering::enabled() && in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true);
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
            'tax_inclusive' => 'boolean',
            'proforma' => 'boolean',
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

    /** "Invoice #42", or "Proforma #42" until an invoice numbered on payment is paid. */
    public function label(): string
    {
        return ($this->proforma ? 'Proforma' : 'Invoice')." #{$this->number}";
    }

    /** "Sales tax (8.25%)" */
    public function taxLabel(): string
    {
        return ($this->tax_name ?? 'Tax').($this->tax_rate ? ' ('.TaxRule::formatRate($this->tax_rate).')' : '');
    }

    /** The store order this is the first invoice of, if any. */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Tries at charging a saved payment method for this invoice, newest first. */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->latest('id');
    }

    /**
     * Recompute subtotal, tax and total from the line items. With
     * tax-inclusive pricing the line amounts already contain the tax, so the
     * total is the sum of the lines and the tax is the part of it that is tax.
     */
    public function recalculate(): static
    {
        $taxable = (int) $this->items()->where('taxable', true)->sum('amount');
        $this->subtotal = (int) $this->items()->sum('amount');

        if ($this->tax_inclusive) {
            $this->tax = TaxRule::taxIncludedIn($taxable, $this->tax_rate);
            $this->total = $this->subtotal;
        } else {
            $this->tax = TaxRule::taxOn($taxable, $this->tax_rate);
            $this->total = $this->subtotal + $this->tax;
        }

        $this->save();

        return $this;
    }

    /** Drafts and unpaid invoices can still have their lines changed, until a credit note is issued against them. */
    public function isEditable(): bool
    {
        return in_array($this->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true) && ! $this->creditNotes()->exists();
    }

    /**
     * Leaves out add funds and mass payment invoices. Their money only goes
     * into credit, so they are not chased, charged or counted as owed.
     */
    public function scopeWithoutFunds(Builder $query): void
    {
        $query->whereDoesntHave('items', fn (Builder $items) => $items->whereIn('type', InvoiceItem::FUNDS_TYPES));
    }

    /** An add funds invoice: paying it adds to the client's credit balance. */
    public function isAddFunds(): bool
    {
        return $this->items()->where('type', InvoiceItem::TYPE_ADD_FUNDS)->exists();
    }

    /** A mass payment invoice: paying it pays the invoices on its lines. */
    public function isMassPayment(): bool
    {
        return $this->items()->where('type', InvoiceItem::TYPE_MASS_PAY)->exists();
    }

    public function amountPaid(): int
    {
        return (int) $this->transactions()->sum('amount');
    }

    /** What's left to pay. Credit notes take their amount off what's owed, as their refunds take it off what's paid. */
    public function balance(): int
    {
        return $this->total - $this->credited() - $this->amountPaid();
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** The total of the credit notes issued against this invoice. */
    public function credited(): int
    {
        return (int) $this->creditNotes()->sum('total');
    }
}
