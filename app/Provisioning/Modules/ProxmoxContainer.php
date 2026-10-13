<?php

namespace App\Provisioning\Modules;

use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;

/**
 * Makes a service's LXC container from the product's OS template, with its
 * cores, memory and disk, the service's password for root, and an address
 * from the server's IP pool (DHCP without one). Proxmox starts it once it is
 * made, which can take a minute, so a long task comes back later.
 */
final class ProxmoxContainer extends ProxmoxBuild
{
    public function run(): ProvisioningResult
    {
        if (! isset($this->service->provisioning_data['vmid'])) {
            $this->startCreate();
        }

        $vmid = (int) $this->service->provisioning_data['vmid'];

        return $this->awaitTask($vmid) ?? $this->check($vmid);
    }

    protected function noun(): string
    {
        return 'container';
    }

    protected function waiting(int $vmid): string
    {
        return "Creating container {$vmid}.";
    }

    protected function taskFailed(string $status): string
    {
        return "Proxmox couldn't create the container: {$status}. Run create again to try with a new one.";
    }

    /** Saves the new container's id, node and address on the service, then asks Proxmox to make it. */
    private function startCreate(): void
    {
        $template = $this->plan->osTemplate();
        $size = $this->plan->size();
        $node = $this->plan->node() ?? $this->api->onlineNode();
        $this->claimAddress();
        $vmid = $this->newId();
        $this->remember(['vmid' => $vmid, 'node' => $node, self::REUSE_ID => null]);

        try {
            $task = $this->api->call('post', "nodes/{$node}/lxc", [
                'vmid' => $vmid,
                'ostemplate' => $template,
                'hostname' => $this->hostname(),
                'description' => $this->description(),
                'password' => (string) $this->service->password,
                'cores' => $size['cores'],
                'memory' => $size['memory'],
                'rootfs' => $this->plan->rootDisk(),
                'net0' => "name=eth0,bridge={$this->plan->bridge()},".($this->addressSettings() ?? 'ip=dhcp'),
                'unprivileged' => 1,
                'start' => 1,
            ]);
        } catch (ModuleError $e) {
            $this->forget(); // Proxmox refused, so nothing was made.

            throw $e;
        }

        $this->remember([self::TASK => $task]);
    }

    /** Makes sure the finished container is the service's and running. Safe to run again. */
    private function check(int $vmid): ProvisioningResult
    {
        $vm = $this->api->vm($vmid);
        $config = $vm === null ? [] : $this->api->call('get', ProxmoxApi::path($vm['node'], $vmid, ProxmoxApi::LXC).'/config');

        if (($problem = $this->problem($vmid, $vm, $config)) !== null) {
            return $problem;
        }

        if ($vm['status'] !== ProxmoxApi::RUNNING) {
            $this->api->start($vm['node'], $vmid, ProxmoxApi::LXC);
        }

        return $this->created($vmid, $vm['node']);
    }
}
