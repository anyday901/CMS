<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an affiliate earned on one paid invoice. It waits (pending) until
 * its clearing date, is then approved and counts towards their balance,
 * and is paid with a payout. A refund or staff can void it first.
 */
class AffiliateCommission extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $fillable = [
        'affiliate_id', 'referral_id', 'invoice_id', 'description', 'amount', 'original_amount', 'currency',
        'status', 'available_at', 'approved_at', 'void_reason', 'payout_id',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'original_amount' => 'integer',
            'available_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(AffiliateReferral::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayout::class);
    }

    /** Pending or approved: not paid out yet, and not void already. */
    public function canBeVoided(): bool
    {
        return in_array($this->status, [self::PENDING, self::APPROVED], true);
    }
}
