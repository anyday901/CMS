<?php

namespace App\Licensing;

use App\Models\License;
use App\Provisioning\Modules\Licensing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Answers a license check from the software: whether the key is good for
 * where it runs. Where the product locks licenses, the first checks lock it
 * to the domain, IP address and directory they come from, up to the
 * product's installs per license.
 */
class LicenseChecker
{
    public const ACTIVE = 'Active';

    public const INVALID = 'Invalid';

    /** What each lock is called in messages. */
    private const NAMES = ['domains' => 'domain', 'ips' => 'IP address', 'directories' => 'directory'];

    /**
     * @param  string  $ip  the address the check came from
     * @return array{0: ?License, 1: array<string, mixed>} the license (null for an unknown key) and the answer
     */
    public function check(string $key, ?string $domain, ?string $directory, string $ip): array
    {
        return DB::transaction(function () use ($key, $domain, $directory, $ip) {
            $license = License::where('key', trim($key))->lockForUpdate()->with(['service.product', 'service.client'])->first();

            if ($license === null || $license->service->product->module !== 'licensing') {
                return [null, ['status' => self::INVALID, 'message' => 'Unknown license key.']];
            }

            $here = ['domains' => self::domain($domain), 'ips' => $ip, 'directories' => self::directory($directory)];
            [$status, $message] = $license->status === License::ACTIVE ? $this->lock($license, $here) : [$license->statusLabel(), match ($license->status) {
                License::SUSPENDED => 'The license is suspended.',
                default => 'The license has expired.',
            }];

            $license->forceFill(['checks' => $license->checks + 1, 'last_checked_at' => now(), 'last_domain' => $here['domains'], 'last_ip' => $ip])->save();

            return [$license, $this->answer($license, $status, $message)];
        });
    }

    /**
     * Checks the install against the license's locks, locking any that still
     * have room. Saves nothing unless every lock passes.
     *
     * @param  array<string, ?string>  $here
     * @return array{string, ?string} the status and why it isn't active
     */
    private function lock(License $license, array $here): array
    {
        $settings = $license->service->product->module_config ?? [];
        $locks = Licensing::LOCKS[$settings['lock'] ?? 'all'] ?? Licensing::LOCKS['all'];
        $installs = max(1, (int) ($settings['installs'] ?? 1));
        $lists = [];

        foreach ($locks as $field) {
            $value = $here[$field];
            $list = $license->{$field} ?? [];

            if ($value === null) {
                return [self::INVALID, 'The check didn\'t send the '.self::NAMES[$field].'.'];
            }

            if (! in_array($value, $list, true)) {
                if (count($list) >= $installs) {
                    return [self::INVALID, "The license isn't for this ".self::NAMES[$field]." ({$value}). Reissue it to move it."];
                }

                $list[] = $value;
            }

            $lists[$field] = $list;
        }

        $license->forceFill($lists);

        return [self::ACTIVE, null];
    }

    /** @return array<string, mixed> */
    private function answer(License $license, string $status, ?string $message): array
    {
        $service = $license->service;

        return [
            'status' => $status,
            'message' => $message,
            'key' => $license->key,
            'product' => $service->product->name,
            'registered_name' => $service->client->fullName(),
            'company' => $service->client->company ?: null,
            'service_status' => $service->status->value,
            'billing_cycle' => $service->billing_cycle->value,
            'next_due_date' => $service->next_due_date?->toDateString(),
            'domains' => $license->domains ?? [],
            'ips' => $license->ips ?? [],
            'directories' => $license->directories ?? [],
        ];
    }

    /** "example.com" from "https://www.Example.com:8443/shop", or null when it isn't a host name. */
    public static function domain(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $host = str_contains($value, '://') ? (string) parse_url($value, PHP_URL_HOST) : Str::before(Str::before($value, '/'), ':');
        $host = Str::chopStart(rtrim($host, '.'), 'www.');

        return $host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false ? $host : null;
    }

    /** The directory with forward slashes and no trailing slash, or null when blank. */
    public static function directory(?string $value): ?string
    {
        $value = rtrim(str_replace('\\', '/', trim((string) $value)), '/');

        return $value === '' ? null : Str::limit($value, 255, '');
    }
}
