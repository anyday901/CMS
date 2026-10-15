<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A payout an affiliate asked for, covering their approved commissions in one currency. */
class AffiliatePayout extends Model
{
    public const REQUESTED = 'requested';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    /** Added to the affiliate's account credit. */
    public const CREDIT = 'credit';

    /** Sent outside the app, such as by PayPal or bank transfer, with a reference. */
    public const MANUAL = 'manual';

    protected $fillable = ['affiliate_id', 'amount', 'currency', 'status', 'method', 'reference', 'notes', 'user_id', 'paid_at'];

    protected $attributes = ['status' => self::REQUESTED];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class, 'payout_id');
    }

    /** The staff member who paid or rejected it. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            self::CREDIT => 'Account credit',
            self::MANUAL => 'Paid outside the app',
            default => '—',
        };
    }
}
