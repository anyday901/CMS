<?php

namespace App\Provisioning\Modules;

use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProductSettings;
use App\Provisioning\ProvisioningResult;

/**
 * A product's Proxmox settings: the template new VMs are cloned from, where
 * they go, and their size, which it sets on a VM.
 */
final class ProxmoxPlan
{
    public const TEMPLATE_LABEL = 'template VM ID';

    private const DEFAULT_DISK = 'scsi0';

    private const DEFAULT_USER = 'root';

    private readonly ProductSettings $settings;

    public function __construct(Service $service)
    {
        $this->settings = ProductSettings::of($service);
    }

    public function template(): int
    {
        return $this->settings->wholeNumber('template', self::TEMPLATE_LABEL);
    }

    /** The node new VMs go on, or null for the template's. */
    public function node(): ?string
    {
        return $this->settings->get('node');
    }

    /** Where new VMs' disks go, or null for the template's storage. */
    public function storage(): ?string
    {
        return $this->settings->get('storage');
    }

    /** The user cloud-init sets up with the service's password. */
    public function user(): string
    {
        return $this->settings->get('user') ?? self::DEFAULT_USER;
    }

    /** @return array{cores: int, memory: int} */
    public function size(): array
    {
        return ['cores' => $this->settings->wholeNumber('cores', 'CPU cores'), 'memory' => $this->settings->wholeNumber('memory', 'memory')];
    }

    /**
     * Sets the VM's cores and memory, with any other $settings, and grows its
     * disk to the plan's size if it is smaller. Returns null when that is
     * done, or the result to report while it isn't.
     *
     * @param  array<string, mixed>  $config  the VM's config before the change
     * @param  array<string, mixed>  $settings
     */
    public function apply(ProxmoxApi $api, string $path, array $config, array $settings = []): ?ProvisioningResult
    {
        $api->call('put', "{$path}/config", $this->size() + $settings);

        $size = $this->settings->get('disk_size');
        $disk = $this->settings->get('disk') ?? self::DEFAULT_DISK;

        if ($size === null) {
            return null;
        }

        $current = self::gigabytes((string) ($config[$disk] ?? ''))
            ?? throw new ModuleError("The VM has no disk {$disk}. Set the product's Disk to the template's disk.");

        if ((float) $size <= $current) {
            return null;
        }

        $status = $api->await($api->call('put', "{$path}/resize", ['disk' => $disk, 'size' => "{$size}G"]));

        return match ($status) {
            ProxmoxApi::TASK_OK => null,
            null => ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Growing the disk to {$size} GB."),
            default => ProvisioningResult::failed("Proxmox couldn't grow the disk: {$status}"),
        };
    }

    /** Disk size in GB from a disk's config, e.g. "local-lvm:vm-105-disk-0,size=32G", or null without one. */
    private static function gigabytes(string $disk): ?float
    {
        if (! preg_match('/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT]?)/', $disk, $match)) {
            return null;
        }

        return (float) $match[1] * match ($match[2]) {
            'K' => 1 / 1024 ** 2,
            'M' => 1 / 1024,
            'G' => 1,
            'T' => 1024,
            default => 1 / 1024 ** 3,
        };
    }
}
