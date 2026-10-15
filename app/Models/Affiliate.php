<?php

namespace App\Models;

use App\Affiliates\AffiliateProgram;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A client in the affiliate program, with the code in their referral link. */
class Affiliate extends Model
{
    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    protected $fillable = ['status', 'commission_type', 'commission_value'];

    protected $attributes = ['status' => self::ACTIVE, 'visits' => 0];

    protected function casts(): array
    {
        return [
            'commission_value' => 'integer',
            'visits' => 'integer',
        ];
    }

    /** A new random code, eight letters and digits with at least one letter, so it never looks like a WHMCS affiliate id. */
    public static function newCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (ctype_digit($code) || static::where('code', $code)->exists());

        return $code;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** The referral link to share. */
    public function link(): string
    {
        return route('affiliates.visit', $this->code);
    }

    /** "10%" or "$5.00 per invoice", from this affiliate's own rate or the program's. */
    public function commissionLabel(): string
    {
        [$type, $value] = $this->commissionRate();

        return $type === self::FIXED
            ? Money::format($value, $this->client?->currency ?? config('billing.currency')).' per invoice'
            : TaxRule::formatRate($value);
    }

    /**
     * The commission type and value this affiliate earns: their own when
     * staff set one, otherwise the program's.
     *
     * @return array{0: string, 1: int}
     */
    public function commissionRate(): array
    {
        return $this->commission_type !== null
            ? [$this->commission_type, (int) $this->commission_value]
            : app(AffiliateProgram::class)->commissionRate();
    }

    /**
     * Approved commissions not yet paid or asked for, by currency.
     *
     * @return array<string, int>
     */
    public function balances(): array
    {
        return $this->totals($this->commissions()->where('status', AffiliateCommission::APPROVED)->whereNull('payout_id'));
    }

    /**
     * Commissions still clearing, by currency.
     *
     * @return array<string, int>
     */
    public function pendingTotals(): array
    {
        return $this->totals($this->commissions()->where('status', AffiliateCommission::PENDING));
    }

    /** @return array<string, int> */
    private function totals(HasMany $commissions): array
    {
        return $commissions->groupBy('currency')->selectRaw('currency, sum(amount) as total')->pluck('total', 'currency')
            ->map(fn ($total) => (int) $total)->filter()->all();
    }
}
