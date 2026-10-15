<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/** An IP address or range that can't use the site, until it expires or staff lift it. */
class IpBan extends Model
{
    use MassPrunable;

    private const CACHE_KEY = 'ip_bans';

    protected $fillable = ['ip_address', 'reason', 'expires_at', 'user_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Expired bans are deleted every night. */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<=', now());
    }

    public function expired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Whether the address is banned. The list is cached until a ban changes. */
    public static function covers(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $bans = Cache::rememberForever(self::CACHE_KEY, fn () => static::active()->get(['ip_address', 'expires_at'])
            ->map(fn (self $ban) => [$ban->ip_address, $ban->expires_at?->getTimestamp()])->all());

        $now = now()->getTimestamp();
        foreach ($bans as [$range, $expires]) {
            if (($expires === null || $expires > $now) && IpUtils::checkIp($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /** An IP address, or a range in CIDR form such as 203.0.113.0/24. */
    public static function validRange(string $value): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits >= 8 && (int) $bits <= $max);
    }
}
