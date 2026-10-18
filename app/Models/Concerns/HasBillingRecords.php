<?php

namespace App\Models\Concerns;

use App\Models\BillableItem;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's billing history: invoices, quotes, billable items, credit notes,
 * credit and payments.
 *
 * @mixin Client
 */
trait HasBillingRecords
{
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function billableItems(): HasMany
    {
        return $this->hasMany(BillableItem::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function creditEntries(): HasMany
    {
        return $this->hasMany(CreditEntry::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Invoices, payments and credit are in the client's currency, so as in
     * WHMCS it can't be changed once there are any.
     */
    public function currencyLocked(): bool
    {
        return $this->exists && ($this->credit_balance !== 0 || $this->invoices()->exists() || $this->transactions()->exists());
    }
}
