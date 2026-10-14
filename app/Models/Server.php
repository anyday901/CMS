<?php

namespace App\Models;

use App\Billing\ServiceLifecycle;
use App\Provisioning\Provisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A machine a provisioning module sets services up on, such as a cPanel or
 * Proxmox server. The password and API token are encrypted with the app key.
 */
class Server extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'module', 'hostname', 'ip_address', 'assigned_ips', 'port', 'secure', 'verify_tls',
        'username', 'password', 'api_token', 'max_accounts', 'active', 'monitor', 'monitor_ports', 'show_status',
    ];

    protected $hidden = ['password', 'api_token'];

    protected $attributes = ['secure' => true, 'verify_tls' => true, 'max_accounts' => 200, 'active' => true, 'monitor' => true];

    /** Monitoring: everything answers. */
    public const UP = 'up';

    /** Monitoring: the module's port answers, but another port or a node doesn't. */
    public const WARNING = 'warning';

    /** Monitoring: the module's port doesn't answer. */
    public const DOWN = 'down';

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'secure' => 'boolean',
            'verify_tls' => 'boolean',
            'password' => 'encrypted',
            'api_token' => 'encrypted',
            'max_accounts' => 'integer',
            'active' => 'boolean',
            'monitor' => 'boolean',
            'show_status' => 'boolean',
            'status_ports' => 'array',
            'status_load' => 'array',
            'status_misses' => 'integer',
            'status_checked_at' => 'datetime',
            'status_since' => 'datetime',
        ];
    }

    public function outages(): HasMany
    {
        return $this->hasMany(ServerOutage::class);
    }

    /** The extra ports to check from monitor_ports, e.g. "80, 443, 21". */
    public function monitoredPorts(): array
    {
        $ports = array_map('intval', preg_split('/[\s,]+/', (string) $this->monitor_ports, -1, PREG_SPLIT_NO_EMPTY));

        return array_values(array_unique(array_filter($ports, fn (int $port) => $port >= 1 && $port <= 65535)));
    }

    /** "Up", "Warning", "Down" or "Not checked yet". */
    public function statusLabel(): string
    {
        return $this->status ? ucfirst($this->status) : 'Not checked yet';
    }

    /**
     * The share of the last $days days (or since the server was added) it
     * wasn't down, from 0 to 100; null before its first check.
     */
    public function uptime(int $days = 30): ?float
    {
        if ($this->status_checked_at === null) {
            return null;
        }

        $since = now()->subDays($days)->max($this->created_at ?? now()->subDays($days));
        $window = max(1, now()->diffInSeconds($since, true));
        $down = $this->outages()->where('status', self::DOWN)
            ->where(fn ($outages) => $outages->whereNull('ended_at')->orWhere('ended_at', '>', $since))
            ->get()
            ->sum(fn (ServerOutage $outage) => ($outage->ended_at ?? now())->diffInSeconds($outage->started_at->max($since), true));

        return round(100 * max(0, 1 - $down / $window), 2);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ServerGroup::class, 'server_group_server')->orderBy('name');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** Services that take up room on the server: pending, active and suspended ones. */
    public function accounts(): HasMany
    {
        return $this->services()->whereIn('status', ServiceLifecycle::NOT_ENDED);
    }

    /** Ended services whose last action is waiting or failed, such as a terminate, so their account may still be on the server. */
    public function accountsToRemove(): HasMany
    {
        return $this->services()->whereNotIn('status', ServiceLifecycle::NOT_ENDED)
            ->whereIn('provisioning_status', [Provisioner::PENDING, Provisioner::FAILED]);
    }

    /** @param  Builder<self>  $query */
    public function scopeWithAccountCount(Builder $query): void
    {
        $query->withCount(['accounts as account_count']);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** How full the server is, from 0 to 1. Needs withAccountCount(). */
    public function usage(): float
    {
        return $this->max_accounts > 0 ? $this->account_count / $this->max_accounts : 1.0;
    }

    public function hasRoom(): bool
    {
        return $this->account_count < $this->max_accounts;
    }

    /** The API's base address: "https://host:port", with $defaultPort unless the server sets its own. */
    public function url(int $defaultPort): string
    {
        return $this->address($this->port ?: $defaultPort);
    }

    /** "https://host:port" (http when the server isn't secure), with an IPv6 address in brackets. */
    public function address(int $port): string
    {
        $host = filter_var($this->hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$this->hostname}]" : $this->hostname;

        return ($this->secure ? 'https' : 'http').'://'.$host.':'.$port;
    }

    /**
     * The addresses in assigned_ips, one per line, each with its prefix
     * length and gateway when given ("203.0.113.10/24 203.0.113.1").
     *
     * @return list<array{address: string, prefix: ?int, gateway: ?string}>
     */
    public function addressPool(): array
    {
        $pool = [];

        foreach (preg_split('/\R/', (string) $this->assigned_ips) as $line) {
            $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);

            if ($parts === []) {
                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $parts[0], 2), 2, null);
            $pool[] = ['address' => $address, 'prefix' => $prefix === null ? null : (int) $prefix, 'gateway' => $parts[1] ?? null];
        }

        return $pool;
    }
}
