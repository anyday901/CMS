<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A software license the licensing module issued for a service. Its status
 * follows the service. The software checks it at /api/licenses/check, and
 * the first checks lock it to the domains, IP addresses and directories it
 * runs from; a reissue lets it move.
 */
class License extends Model
{
    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    /** The service has ended. */
    public const EXPIRED = 'expired';

    protected $fillable = ['service_id', 'key', 'status', 'domains', 'ips', 'directories', 'reissues', 'checks', 'last_checked_at', 'last_domain', 'last_ip'];

    protected $attributes = ['status' => self::ACTIVE, 'reissues' => 0, 'checks' => 0];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'ips' => 'array',
            'directories' => 'array',
            'reissues' => 'integer',
            'checks' => 'integer',
            'last_checked_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Clears the domains, IP addresses and directories, so the next check locks the license to wherever it runs then. */
    public function reissue(): void
    {
        $this->forceFill(['domains' => null, 'ips' => null, 'directories' => null, 'reissues' => $this->reissues + 1])->save();
    }

    /** "Active", "Suspended" or "Expired", as the check answers it. */
    public function statusLabel(): string
    {
        return ucfirst($this->status);
    }
}
