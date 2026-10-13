<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A service's disk and bandwidth use in one calendar month, in MB, from its
 * server's last reading that month. Overages are billed from it once the
 * month is over.
 */
class ServiceUsage extends Model
{
    protected $fillable = [
        'service_id', 'period', 'disk_mb', 'disk_limit_mb', 'bandwidth_mb', 'bandwidth_limit_mb',
        'counter_bytes', 'counted_bytes', 'read_at', 'billed_at', 'invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'disk_mb' => 'integer',
            'disk_limit_mb' => 'integer',
            'bandwidth_mb' => 'integer',
            'bandwidth_limit_mb' => 'integer',
            'counter_bytes' => 'integer',
            'counted_bytes' => 'integer',
            'read_at' => 'datetime',
            'billed_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** MB of disk over what the product includes, or 0. */
    public function diskOverMb(Product $product): int
    {
        return $product->overage_disk_mb === null ? 0 : max(0, (int) $this->disk_mb - $product->overage_disk_mb);
    }

    /** MB of bandwidth over what the product includes, or 0. */
    public function bandwidthOverMb(Product $product): int
    {
        return $product->overage_bandwidth_mb === null ? 0 : max(0, (int) $this->bandwidth_mb - $product->overage_bandwidth_mb);
    }

    /** How much of $limit is used, from 0 to 100, or null without a limit. */
    public static function percent(?int $used, ?int $limit): ?int
    {
        return $limit ? min(100, (int) round(100 * (int) $used / $limit)) : null;
    }

    /** "1.5 GB" or "300 MB". */
    public static function size(?int $mb): string
    {
        return match (true) {
            $mb === null => '—',
            $mb >= 1024 => rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB',
            default => number_format($mb).' MB',
        };
    }
}
