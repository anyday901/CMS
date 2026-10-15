<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A key for the REST API (see docs/api.md). The key looks like
 * cms_ab12cd34_<40 random characters>; the part before the second
 * underscore is kept as the prefix to find it, and only a SHA-256 hash of
 * the whole key is stored, so it can't be read back.
 */
class ApiKey extends Model
{
    /** Where the authenticated key is kept on the request. */
    public const REQUEST_ATTRIBUTE = 'api_key';

    /** What a key can be allowed to do, with labels for the admin form. */
    public const ABILITIES = [
        'clients:read' => 'See clients',
        'clients:write' => 'Add and update clients',
        'services:read' => 'See services',
        'services:write' => 'Suspend, unsuspend and terminate services',
        'invoices:read' => 'See invoices and their payments',
        'invoices:write' => 'Record payments on invoices',
        'orders:read' => 'See orders',
        'products:read' => 'See products and prices',
        'tickets:read' => 'See tickets and their replies',
        'tickets:write' => 'Open tickets and reply to them',
    ];

    private const START = 'cms_';

    /** Last use is saved at most this often, so busy keys don't write on every request. */
    private const USAGE_INTERVAL_SECONDS = 60;

    protected $fillable = ['name', 'abilities', 'allowed_ips', 'expires_at', 'user_id'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Makes a key and returns it with the plain key, which is never stored.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: self, 1: string}
     */
    public static function generate(array $attributes): array
    {
        do {
            $prefix = self::START.Str::lower(Str::random(8));
        } while (static::where('prefix', $prefix)->exists());

        $plain = $prefix.'_'.Str::random(40);

        $key = new static($attributes);
        $key->forceFill(['prefix' => $prefix, 'secret_hash' => hash('sha256', $plain)])->save();

        return [$key, $plain];
    }

    /** The key a bearer token belongs to, revoked or not, or null if it doesn't match one. */
    public static function findByToken(?string $token): ?self
    {
        if ($token === null || preg_match('/^(cms_[a-z0-9]{8})_[A-Za-z0-9]{40}$/', $token, $match) !== 1) {
            return null;
        }

        $key = static::where('prefix', $match[1])->first();

        return $key && hash_equals($key->secret_hash, hash('sha256', $token)) ? $key : null;
    }

    /** The key the current API request is using, if any. */
    public static function current(): ?self
    {
        $key = request()->attributes->get(self::REQUEST_ATTRIBUTE);

        return $key instanceof self ? $key : null;
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function allowsIp(?string $ip): bool
    {
        return empty($this->allowed_ips) || ($ip !== null && IpUtils::checkIp($ip, $this->allowed_ips));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'Revoked',
            $this->isExpired() => 'Expired',
            default => 'Active',
        };
    }

    /**
     * Saves when and where the key was last used: at most once a minute, or
     * straight away from a new address. Leaves updated_at alone, which means
     * the key's settings changed.
     */
    public function recordUse(?string $ip): void
    {
        $stale = $this->last_used_at === null || $this->last_used_at->diffInSeconds(now()) >= self::USAGE_INTERVAL_SECONDS;

        if (! $stale && $this->last_used_ip === $ip) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip]);
        static::query()->toBase()->where('id', $this->id)->update(['last_used_at' => $this->last_used_at, 'last_used_ip' => $ip]);
        $this->syncOriginal();
    }
}
