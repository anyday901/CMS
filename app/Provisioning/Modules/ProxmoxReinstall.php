<?php

namespace App\Provisioning\Modules;

use App\Models\Service;
use App\Provisioning\ProvisioningResult;

/**
 * Wipes a service's VM or container and makes it again from the product's
 * template, keeping its id, address and password. Each step can come back
 * later, so the stage is saved on the service: removing the old one first,
 * then building the new one.
 */
final class ProxmoxReinstall
{
    private const STAGE = 'reinstall';

    private const BUILDING = 'building';

    public function __construct(private readonly ProxmoxApi $api, private readonly Service $service) {}

    public function run(ProxmoxBuild $build): ProvisioningResult
    {
        if (($this->service->provisioning_data[self::STAGE] ?? null) !== self::BUILDING) {
            $problem = $this->remove();

            if ($problem !== null) {
                return $problem;
            }
        }

        $result = $build->run();

        if (! $result->ok) {
            return $result;
        }

        $this->remember([self::STAGE => null]);

        return ProvisioningResult::ok(str_replace('Created', 'Reinstalled', (string) $result->message), $result->data);
    }

    /** Stops and deletes the current VM or container. Returns null once it is gone, or the result to report meanwhile. */
    private function remove(): ?ProvisioningResult
    {
        $vmid = $this->service->provisioning_data['vmid'] ?? null;
        $vm = $vmid === null ? null : $this->api->vm((int) $vmid);

        if ($vm !== null) {
            $path = ProxmoxApi::path($vm['node'], (int) $vmid, $vm['type']);
            $name = ($vm['type'] === ProxmoxApi::LXC ? 'Container' : 'VM')." {$vmid}";
            $config = $this->api->call('get', "{$path}/config");

            if (! str_contains((string) ($config['description'] ?? ''), "[service {$this->service->id}]")) {
                return ProvisioningResult::failed("{$name} on Proxmox isn't this service's, so it was left alone.");
            }

            $this->remember([self::STAGE => 'removing']);
            $status = $vm['status'] === ProxmoxApi::RUNNING ? $this->api->await($this->api->call('post', "{$path}/status/stop")) : ProxmoxApi::TASK_OK;
            $status = $status === ProxmoxApi::TASK_OK ? $this->api->await($this->api->call('delete', $path, ['purge' => 1, 'destroy-unreferenced-disks' => 1])) : $status;

            if ($status !== ProxmoxApi::TASK_OK) {
                return $status === null
                    ? ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Removing {$name} to reinstall it.")
                    : ProvisioningResult::failed("Proxmox couldn't remove {$name} to reinstall it: {$status}");
            }
        }

        // The address stays the service's; the new one gets the same id when it's free.
        $this->remember(['vmid' => null, 'node' => null, ProxmoxBuild::REUSE_ID => $vmid, self::STAGE => self::BUILDING]);

        return null;
    }

    private function remember(array $values): void
    {
        $data = array_filter([...$this->service->provisioning_data ?? [], ...$values], fn ($value) => $value !== null);
        $this->service->forceFill(['provisioning_data' => $data ?: null])->save();
    }
}
