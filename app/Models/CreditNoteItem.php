<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The part of one invoice line a credit note gives back. */
class CreditNoteItem extends Model
{
    protected $fillable = ['invoice_item_id', 'description', 'amount', 'taxable'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'taxable' => 'boolean',
        ];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
