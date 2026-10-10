<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change to a client's credit balance. Written only by CreditLedger. */
class CreditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['client_id', 'amount', 'balance_after', 'description', 'invoice_id', 'transaction_id', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
