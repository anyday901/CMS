<?php

namespace App\Provisioning\Modules;

use App\Models\License;
use App\Models\Service;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use Illuminate\Support\Str;

/**
 * Software licensing: each service is a license key for your own software,
 * which checks it at /api/licenses/check (see docs/licensing.md). The key
 * is issued when the service starts, stops working while it is suspended
 * and expires when it ends. The first checks lock it to the domain, IP
 * address and directory the software runs from, as the product sets.
 */
class Licensing implements ProvisioningModule
{
    /** What a license is locked to, by the product's lock setting. */
    public const LOCKS = [
        'all' => ['domains', 'ips', 'directories'],
        'domain_ip' => ['domains', 'ips'],
        'domain' => ['domains'],
        'none' => [],
    ];

    /** Letters and digits for keys, without ones easily mistaken for each other. */
    private const KEY_CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function key(): string
    {
        return 'licensing';
    }

    public function label(): string
    {
        return 'Software licensing';
    }

    public function configFields(): array
    {
        return [
            'secret' => ['label' => 'Signing secret', 'required' => true, 'help' => 'Answers to license checks are signed with this (HMAC-SHA256), so the software can tell a real answer from a faked one. Put the same secret in the software, and keep it out of anything you share. At least 16 characters.', 'min' => 16],
            'prefix' => ['label' => 'Key prefix', 'help' => 'Put at the start of each new key, e.g. MYAPP-.'],
            'lock' => ['label' => 'Lock to', 'type' => 'select', 'options' => [
                'all' => 'Domain, IP address and directory',
                'domain_ip' => 'Domain and IP address',
                'domain' => 'Domain only',
                'none' => 'Nothing: any install works',
            ], 'help' => 'Each license is locked to where it is first used. A reissue lets it move.'],
            'installs' => ['label' => 'Installs per license', 'type' => 'number', 'help' => 'How many domains (and IP addresses and directories) one license may lock to. 1 when blank.'],
            'reissue' => ['label' => 'Reissue', 'type' => 'select', 'options' => ['client' => 'Clients reissue their own license from the portal', 'staff' => 'Only staff reissue licenses']],
        ];
    }

    /** Issues the license key, or keeps the one an earlier run issued. */
    public function create(Service $service): ProvisioningResult
    {
        $license = $service->license()->first();

        if ($license !== null) {
            $license->update(['status' => License::ACTIVE]);

            return ProvisioningResult::ok("The license {$license->key} was already issued.");
        }

        $license = $service->license()->create(['key' => $this->newKey($service), 'status' => License::ACTIVE]);

        return ProvisioningResult::ok("Issued the license {$license->key}.");
    }

    public function suspend(Service $service): ProvisioningResult
    {
        return $this->setStatus($service, License::SUSPENDED, 'Suspended');
    }

    public function unsuspend(Service $service): ProvisioningResult
    {
        return $this->setStatus($service, License::ACTIVE, 'Reactivated');
    }

    public function terminate(Service $service): ProvisioningResult
    {
        return $this->setStatus($service, License::EXPIRED, 'Expired');
    }

    /** Whether clients may reissue their own licenses of the service's product. */
    public static function clientsReissue(Service $service): bool
    {
        return ($service->product->module_config['reissue'] ?? 'client') !== 'staff';
    }

    private function setStatus(Service $service, string $status, string $done): ProvisioningResult
    {
        $license = $service->license()->first();

        if ($license === null) {
            return ProvisioningResult::ok('The service has no license yet.');
        }

        $license->update(['status' => $status]);

        return ProvisioningResult::ok("{$done} the license {$license->key}.");
    }

    /** e.g. MYAPP-7KQ2M-XW9PA-3HTRD-QZ4NB, unused by any other license. */
    private function newKey(Service $service): string
    {
        $prefix = trim((string) ($service->product->module_config['prefix'] ?? ''));

        do {
            $groups = array_map(fn () => implode('', array_map(fn () => self::KEY_CHARACTERS[random_int(0, strlen(self::KEY_CHARACTERS) - 1)], range(1, 5))), range(1, 4));
            $key = Str::limit($prefix, 40, '').implode('-', $groups);
        } while (License::where('key', $key)->exists());

        return $key;
    }
}
