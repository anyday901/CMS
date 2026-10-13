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
        'username', 'password', 'api_token', 'max_accounts', 'active',
    ];

    protected $hidden = ['password', 'api_token'];

    protected $attributes = ['secure' => true, 'verify_tls' => true, 'max_accounts' => 200, 'active' => true];

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
        ];
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
