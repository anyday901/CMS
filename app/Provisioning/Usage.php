<?php

namespace App\Provisioning;

/**
 * An account's disk and bandwidth use as its server reports it, in MB. A
 * limit of null means unlimited, or that the server doesn't say.
 */
final readonly class Usage
{
    private const MB = 1024 * 1024;

    /**
     * @param  int|null  $bandwidthMb  used so far this month
     * @param  int|null  $trafficCounter  bytes counted since the counter started, for servers that report a running counter (which can reset) instead of a monthly total; the collector works out the month's bandwidth from it
     */
    public function __construct(
        public ?int $diskMb = null,
        public ?int $diskLimitMb = null,
        public ?int $bandwidthMb = null,
        public ?int $bandwidthLimitMb = null,
        public ?int $trafficCounter = null,
    ) {}

    /** Bytes as whole MB (rounded down), or null for anything that isn't a positive number of bytes. */
    public static function fromBytes(mixed $bytes): ?int
    {
        return is_numeric($bytes) && $bytes > 0 ? intdiv((int) $bytes, self::MB) : null;
    }

    /**
     * A size such as "1500M", "2.5G" or "512" (in $unit) as whole MB, or null
     * for "unlimited", "none", 0 and anything else that isn't a size.
     */
    public static function parse(mixed $size, string $unit = 'M'): ?int
    {
        if (! is_scalar($size) || ! preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGT]?)B?\s*$/i', (string) $size, $match)) {
            return null;
        }

        $mb = (float) $match[1] * match (strtoupper($match[2] !== '' ? $match[2] : $unit)) {
            'K' => 1 / 1024,
            'G' => 1024,
            'T' => 1024 ** 2,
            default => 1,
        };

        return $mb > 0 ? (int) round($mb) : null;
    }
}
