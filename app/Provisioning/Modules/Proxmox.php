<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;

/**
 * Proxmox VE virtual machines, through its API on port 8006. Each service is
 * a QEMU VM cloned from the product's cloud-init template (see
 * ProxmoxClone), with the product's CPU cores, memory and disk size, the
 * service's username and password set by cloud-init, and an address from
 * the server's IP pool when it has one. Logs in with an API token, or a user
 * and password.
 */
class Proxmox extends ServerModule
{
    private const PORT = 8006;

    public function key(): string
    {
        return 'proxmox';
    }

    public function label(): string
    {
        return 'Proxmox VE';
    }

    public function configFields(): array
    {
        return [
            'template' => ['label' => 'Template VM ID', 'type' => 'number', 'required' => true, 'help' => 'The VM ID of a cloud-init template to clone, e.g. 9000.'],
            'cores' => ['label' => 'CPU cores', 'type' => 'number', 'required' => true],
            'memory' => ['label' => 'Memory (MB)', 'type' => 'number', 'required' => true],
            'disk_size' => ['label' => 'Disk size (GB)', 'type' => 'number', 'help' => 'Grows the template\'s disk to this size. Disks are never shrunk.'],
            'disk' => ['label' => 'Disk', 'help' => 'The disk to grow. Defaults to scsi0.'],
            'storage' => ['label' => 'Storage', 'help' => 'Where new VMs\' disks go. Defaults to the template\'s storage.'],
            'node' => ['label' => 'Node', 'help' => 'The node new VMs go on. Defaults to the template\'s node; another node needs the template on shared storage.'],
            'user' => ['label' => 'Login user', 'help' => 'The user cloud-init sets up with the service\'s password. Defaults to root.'],
        ];
    }

    public function defaultPort(bool $secure): int
    {
        return self::PORT;
    }

    public function serverFields(): array
    {
        return [
            'username' => ['help' => 'An API token ID such as root@pam!billing, or a user such as root@pam.', 'required' => true],
            'api_token' => ['help' => 'The API token\'s secret (recommended). Leave it blank to log in with the password.'],
            'password' => ['help' => 'Only needed without an API token.'],
            'assigned_ips' => ['help' => 'Addresses for new VMs, one per line with the prefix length and gateway, e.g. 203.0.113.10/24 203.0.113.1. Each VM gets the first free one; with none, VMs keep the template\'s network settings.'],
        ];
    }

    public function needsDomain(): bool
    {
        return false;
    }

    public function loginDetails(Service $service): array
    {
        return array_filter([
            'IP address' => $service->provisioning_data['ip'] ?? null,
            'Username' => $service->username,
        ]);
    }

    protected function checkConnection(Server $server): ProvisioningResult
    {
        $version = $this->api($server)->call('get', 'version')['version'] ?? null;

        return ProvisioningResult::ok('Connected to Proxmox VE'.($version ? " {$version}" : '').'.');
    }

    /** The VM's login user is the product's, not one made from the domain. */
    protected function newUsername(Service $service, Server $server): string
    {
        return (new ProxmoxPlan($service))->user();
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        return (new ProxmoxClone($this->api($server), $service, $server))->run();
    }

    protected function suspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);

        if ($vm['status'] !== ProxmoxApi::RUNNING) {
            return ProvisioningResult::ok("VM {$vmid} was already stopped.");
        }

        $api->call('post', ProxmoxApi::path($vm['node'], $vmid).'/status/shutdown', ['forceStop' => 1, 'timeout' => 60]);

        return ProvisioningResult::ok("Shutting down VM {$vmid}. Proxmox turns it off after a minute if it hasn't shut down.");
    }

    protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);

        if ($vm['status'] === ProxmoxApi::RUNNING) {
            return ProvisioningResult::ok("VM {$vmid} was already running.");
        }

        $api->start($vm['node'], $vmid);

        return ProvisioningResult::ok("Started VM {$vmid}.");
    }

    /** Stops and deletes the VM with its disks, and frees its address. */
    protected function terminateAccount(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        $vmid = $service->provisioning_data['vmid'] ?? null;
        $vm = $vmid === null ? null : $api->vm((int) $vmid);

        if ($vm === null) {
            return ProvisioningResult::ok($vmid === null ? 'The service had no VM to remove.' : "VM {$vmid} was already gone.", ['ip' => null]);
        }

        $path = ProxmoxApi::path($vm['node'], (int) $vmid);

        if ($vm['status'] === ProxmoxApi::RUNNING) {
            $status = $api->await($api->call('post', "{$path}/status/stop"));

            if ($status !== ProxmoxApi::TASK_OK) {
                return $status === null
                    ? ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Stopping VM {$vmid}.")
                    : ProvisioningResult::failed("Proxmox couldn't stop VM {$vmid}: {$status}");
            }
        }

        $status = $api->await($api->call('delete', $path, ['purge' => 1, 'destroy-unreferenced-disks' => 1]));

        return match ($status) {
            ProxmoxApi::TASK_OK => ProvisioningResult::ok("Removed VM {$vmid} from {$vm['node']}.", ['ip' => null]),
            null => ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Removing VM {$vmid}."),
            default => ProvisioningResult::failed("Proxmox couldn't remove VM {$vmid}: {$status}"),
        };
    }

    /** Sets the new product's cores, memory and disk, and restarts a running VM so they apply. */
    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);
        $path = ProxmoxApi::path($vm['node'], $vmid);
        $config = $api->call('get', "{$path}/config");

        if (filled($config['lock'] ?? null)) {
            return ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "VM {$vmid} is locked ({$config['lock']}).");
        }

        $plan = new ProxmoxPlan($service);
        $problem = $plan->apply($api, $path, $config);
        $restart = $problem === null && $vm['status'] === ProxmoxApi::RUNNING;

        if ($restart) {
            $api->call('post', "{$path}/status/reboot");
        }

        $size = $plan->size();

        return $problem ?? ProvisioningResult::ok(
            "Set VM {$vmid} to {$size['cores']} cores and {$size['memory']} MB of memory".($restart ? ', and restarted it so they apply.' : '.'),
        );
    }

    /** Sets the password inside the running VM, through the QEMU guest agent. */
    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);
        $username = ServerAccount::username($service);

        if ($vm['status'] !== ProxmoxApi::RUNNING) {
            return ProvisioningResult::failed("VM {$vmid} is stopped. Start it, then run change password again.");
        }

        $api->call('post', ProxmoxApi::path($vm['node'], $vmid).'/agent/set-user-password', ['username' => $username, 'password' => $service->password]);

        return ProvisioningResult::ok("Changed the password of {$username} on VM {$vmid}.");
    }

    private function api(Server $server): ProxmoxApi
    {
        return new ProxmoxApi($server, self::PORT);
    }

    /**
     * @return array{int, array{node: string, status: string, template: bool}} the service's VM id and the VM
     */
    private function existingVm(ProxmoxApi $api, Service $service): array
    {
        $vmid = $service->provisioning_data['vmid'] ?? throw new ModuleError('The service has no VM yet. Run create first.');

        return [(int) $vmid, $api->vm((int) $vmid) ?? throw new ModuleError("VM {$vmid} isn't on Proxmox.")];
    }
}
