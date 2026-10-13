<?php

namespace App\Provisioning\Modules;

use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;

/**
 * Makes a service's VM: clones the product's template into a new VM, then
 * sizes it, sets its login and address, and starts it. Copying a big disk
 * can take minutes, so long steps come back later.
 */
final class ProxmoxClone extends ProxmoxBuild
{
    public function run(): ProvisioningResult
    {
        if (! isset($this->service->provisioning_data['vmid'])) {
            $this->startClone();
        }

        $vmid = (int) $this->service->provisioning_data['vmid'];

        return $this->awaitTask($vmid) ?? $this->setUp($vmid);
    }

    protected function noun(): string
    {
        return 'VM';
    }

    protected function waiting(int $vmid): string
    {
        return "Cloning the template into VM {$vmid}.";
    }

    protected function taskFailed(string $status): string
    {
        return "Proxmox couldn't clone the template: {$status}. Run create again to try with a new VM.";
    }

    /** Saves the new VM's id, node and address on the service, then starts cloning the template into it. */
    private function startClone(): void
    {
        $templateId = $this->plan->template();
        $template = $this->api->vm($templateId);

        if ($template === null || ! $template['template'] || $template['type'] !== ProxmoxApi::QEMU) {
            throw new ModuleError("Proxmox has no VM template with the VM ID {$templateId}. Check the product's ".ProxmoxPlan::TEMPLATE_LABEL.'.');
        }

        $node = $this->plan->node() ?? $template['node'];
        $this->claimAddress();
        $vmid = $this->newId();
        $this->remember(['vmid' => $vmid, 'node' => $node, self::REUSE_ID => null]);

        try {
            $task = $this->api->call('post', ProxmoxApi::path($template['node'], $templateId).'/clone', array_filter([
                'newid' => $vmid,
                'name' => $this->hostname(),
                'description' => $this->description(),
                'full' => 1,
                'storage' => $this->plan->storage(),
                'target' => $node !== $template['node'] ? $node : null,
            ], fn ($value) => $value !== null));
        } catch (ModuleError $e) {
            $this->forget(); // Proxmox refused, so nothing was made.

            throw $e;
        }

        $this->remember([self::TASK => $task]);
    }

    /** Sizes the cloned VM, sets its login and address, and starts it. Safe to run again. */
    private function setUp(int $vmid): ProvisioningResult
    {
        $vm = $this->api->vm($vmid);
        $path = $vm === null ? null : ProxmoxApi::path($vm['node'], $vmid);
        $config = $path === null ? [] : $this->api->call('get', "{$path}/config");

        if (($problem = $this->problem($vmid, $vm, $config)) !== null) {
            return $problem;
        }

        $problem = $this->plan->apply($this->api, $path, $config, array_filter([
            'ciuser' => $this->service->username,
            'cipassword' => $this->service->password,
            'ipconfig0' => $this->addressSettings(),
        ]));

        if ($problem === null && $vm['status'] !== ProxmoxApi::RUNNING) {
            $this->api->start($vm['node'], $vmid);
        }

        return $problem ?? $this->created($vmid, $vm['node']);
    }
}
