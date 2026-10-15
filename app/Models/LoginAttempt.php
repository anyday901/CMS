<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One portal or admin login, and how it went. Kept for the days set on the Security page. */
class LoginAttempt extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const PORTAL = 'portal';

    public const ADMIN = 'admin';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const LOCKED = 'locked';

    public const TWO_FACTOR_FAILED = 'two_factor_failed';

    /** Outcomes that count towards a lockout and an automatic ban. */
    public const FAILURES = [self::FAILED, self::TWO_FACTOR_FAILED];

    protected $fillable = ['area', 'email', 'account_type', 'account_id', 'client_id', 'ip_address', 'user_agent', 'outcome', 'created_at'];

    public function account(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(max(1, (int) config('security.login_history_days'))));
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::SUCCESS;
    }

    public function outcomeLabel(): string
    {
        return match ($this->outcome) {
            self::SUCCESS => 'Signed in',
            self::FAILED => 'Wrong email or password',
            self::LOCKED => 'Locked out',
            self::TWO_FACTOR_FAILED => 'Wrong two-factor code',
            default => $this->outcome,
        };
    }

    /** Who tried, for lists: the account's name when there is one. */
    public function who(): string
    {
        return match (true) {
            $this->account instanceof ClientContact => "{$this->account->fullName()} (contact)",
            $this->account instanceof Client => $this->account->fullName(),
            $this->account instanceof User => $this->account->name,
            default => $this->email ?? 'Unknown',
        };
    }

    /** The browser, shortened, for lists. */
    public function browser(): string
    {
        $agent = (string) $this->user_agent;

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            $agent === '' => 'Unknown browser',
            default => 'Other browser',
        };

        $system = match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system ? "{$browser} on {$system}" : $browser;
    }
}
