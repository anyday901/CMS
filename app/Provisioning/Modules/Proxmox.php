<?php

namespace App\Provisioning\Modules;

use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\Reinstalls;
use App\Provisioning\ServerAccount;
use App\Provisioning\SplitsAccountKinds;

/**
 * Proxmox VE virtual machines and containers, through its API on port 8006.
 * Each service is either a QEMU VM cloned from the product's cloud-init
 * template (see ProxmoxClone), with the service's username and password set
 * by cloud-init, or an LXC container made from an OS template (see
 * ProxmoxContainer) with the password for root. Both get the product's CPU
 * cores, memory and disk size, and an address from the server's IP pool
 * when it has one. Either can be reinstalled from its template. Logs in with
 * an API token, or a user and password.
 */
class Proxmox extends ServerModule implements Reinstalls, SplitsAccountKinds
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
            'type' => ['label' => 'Type', 'type' => 'select', 'options' => ['vm' => 'Virtual machine (QEMU)', ProxmoxPlan::CONTAINER => 'Container (LXC)'], 'help' => 'VMs are cloned from a template VM; containers are made from an OS template.'],
            'template' => ['label' => 'Template VM ID', 'type' => 'number', 'help' => 'VMs: the VM ID of a cloud-init template to clone, e.g. 9000.'],
            'ostemplate' => ['label' => 'Container template', 'help' => 'Containers: the OS template, e.g. local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst.'],
            'cores' => ['label' => 'CPU cores', 'type' => 'number', 'required' => true],
            'memory' => ['label' => 'Memory (MB)', 'type' => 'number', 'required' => true],
            'disk_size' => ['label' => 'Disk size (GB)', 'type' => 'number', 'help' => 'VMs: grows the template\'s disk to this size. Containers: the root disk, 8 GB when blank. Disks are never shrunk.'],
            'disk' => ['label' => 'Disk', 'help' => 'VMs: the disk to grow. Defaults to scsi0.'],
            'storage' => ['label' => 'Storage', 'help' => 'Where new disks go. Defaults to the template\'s storage for VMs, local-lvm for containers.'],
            'bridge' => ['label' => 'Network bridge', 'help' => 'Containers: the bridge their network is on. Defaults to vmbr0.'],
            'node' => ['label' => 'Node', 'help' => 'The node new VMs and containers go on. Defaults to the template\'s node for VMs (another node needs the template on shared storage), the first node online for containers.'],
            'user' => ['label' => 'Login user', 'help' => 'VMs: the user cloud-init sets up with the service\'s password. Defaults to root. Containers always use root.'],
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

    /** A VM can't become a container or the other way round, so that change builds a new one. */
    public function needsNewAccount(Product $from, Product $to): bool
    {
        $container = fn (Product $product) => ($product->module_config['type'] ?? null) === ProxmoxPlan::CONTAINER;

        return $container($from) !== $container($to);
    }

    /** Containers have no guest agent to set a password through. */
    public function canChangePassword(Service $service): bool
    {
        return ! (new ProxmoxPlan($service))->container();
    }

    /** Wipes the VM or container and makes it again from the product's template, keeping its id, address and password. */
    public function reinstall(Service $service): ProvisioningResult
    {
        return $this->onServer($service, function (Server $server) use ($service) {
            ServerAccount::fillLogin($service, fn () => $this->newUsername($service, $server));
            $api = $this->api($server);

            return (new ProxmoxReinstall($api, $service, $server))->run($this->build($api, $service, $server));
        });
    }

    /** The login user is the product's, not one made from the domain. */
    protected function newUsername(Service $service, Server $server): string
    {
        return (new ProxmoxPlan($service))->user();
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        return $this->build($this->api($server), $service, $server)->run();
    }

    protected function suspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);

        $name = self::name($vmid, $vm);

        if ($vm['status'] !== ProxmoxApi::RUNNING) {
            return ProvisioningResult::ok("{$name} was already stopped.");
        }

        $api->call('post', ProxmoxApi::path($vm['node'], $vmid, $vm['type']).'/status/shutdown', ['forceStop' => 1, 'timeout' => 60]);

        return ProvisioningResult::ok("Shutting down {$name}. Proxmox turns it off after a minute if it hasn't shut down.");
    }

    protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);

        $name = self::name($vmid, $vm);

        if ($vm['status'] === ProxmoxApi::RUNNING) {
            return ProvisioningResult::ok("{$name} was already running.");
        }

        $api->start($vm['node'], $vmid, $vm['type']);

        return ProvisioningResult::ok("Started {$name}.");
    }

    /** Stops and deletes the VM or container with its disks, and frees its address. */
    protected function terminateAccount(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        $vmid = $service->provisioning_data['vmid'] ?? null;
        $vm = $vmid === null ? null : $api->vm((int) $vmid);

        if ($vm === null) {
            return ProvisioningResult::ok($vmid === null ? 'The service had nothing on Proxmox to remove.' : "{$vmid} was already gone from Proxmox.", ['ip' => null]);
        }

        $name = self::name((int) $vmid, $vm);
        $path = ProxmoxApi::path($vm['node'], (int) $vmid, $vm['type']);

        if ($vm['status'] === ProxmoxApi::RUNNING) {
            $status = $api->await($api->call('post', "{$path}/status/stop"));

            if ($status !== ProxmoxApi::TASK_OK) {
                return $status === null
                    ? ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Stopping {$name}.")
                    : ProvisioningResult::failed("Proxmox couldn't stop {$name}: {$status}");
            }
        }

        $status = $api->await($api->call('delete', $path, ['purge' => 1, 'destroy-unreferenced-disks' => 1]));

        return match ($status) {
            ProxmoxApi::TASK_OK => ProvisioningResult::ok("Removed {$name} from {$vm['node']}.", ['ip' => null]),
            null => ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Removing {$name}."),
            default => ProvisioningResult::failed("Proxmox couldn't remove {$name}: {$status}"),
        };
    }

    /**
     * Sets the new product's cores, memory and disk, and restarts a running
     * VM so they apply. Containers take them while running.
     */
    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);
        $name = self::name($vmid, $vm);
        $path = ProxmoxApi::path($vm['node'], $vmid, $vm['type']);
        $config = $api->call('get', "{$path}/config");

        if (filled($config['lock'] ?? null)) {
            return ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "{$name} is locked ({$config['lock']}).");
        }

        $plan = new ProxmoxPlan($service);
        $problem = $plan->apply($api, $path, $config);
        $restart = $problem === null && $vm['status'] === ProxmoxApi::RUNNING && $vm['type'] === ProxmoxApi::QEMU;

        if ($restart) {
            $api->call('post', "{$path}/status/reboot");
        }

        $size = $plan->size();

        return $problem ?? ProvisioningResult::ok(
            "Set {$name} to {$size['cores']} cores and {$size['memory']} MB of memory".($restart ? ', and restarted it so they apply.' : '.'),
        );
    }

    /** Sets the password inside the running VM, through the QEMU guest agent. */
    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        $api = $this->api($server);
        [$vmid, $vm] = $this->existingVm($api, $service);
        $username = ServerAccount::username($service);

        if ($vm['type'] !== ProxmoxApi::QEMU) {
            return ProvisioningResult::failed("Proxmox can't set a password inside container {$vmid}. Reinstall it, or change the password inside the container.");
        }

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

    /** What makes the service's VM or container, by the product's type. */
    private function build(ProxmoxApi $api, Service $service, Server $server): ProxmoxBuild
    {
        return (new ProxmoxPlan($service))->container()
            ? new ProxmoxContainer($api, $service, $server)
            : new ProxmoxClone($api, $service, $server);
    }

    /**
     * @return array{int, array{node: string, status: string, template: bool, type: string}} the service's VM id and the VM or container
     */
    private function existingVm(ProxmoxApi $api, Service $service): array
    {
        $vmid = $service->provisioning_data['vmid'] ?? throw new ModuleError('The service has nothing on Proxmox yet. Run create first.');

        return [(int) $vmid, $api->vm((int) $vmid) ?? throw new ModuleError("{$vmid} isn't on Proxmox.")];
    }

    /**
     * "VM 105" or "Container 105".
     *
     * @param  array{type: string}  $vm
     */
    private static function name(int $vmid, array $vm): string
    {
        return ($vm['type'] === ProxmoxApi::LXC ? 'Container' : 'VM')." {$vmid}";
    }
}
