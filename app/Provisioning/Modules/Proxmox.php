<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Proxmox VE virtual machines, through its API on port 8006. Each service is
 * a QEMU VM cloned from the product's cloud-init template, with the
 * product's CPU cores, memory and disk size, the service's username and
 * password set by cloud-init, and an address from the server's IP pool when
 * it has one. Logs in with an API token, or a user and password.
 *
 * Copying a big disk can take minutes, so long steps come back later
 * (ProvisioningResult::later()) and pick up where they left off: the VM's id
 * is saved on the service before the clone starts.
 */
class Proxmox extends ServerModule
{
    private const PORT = 8006;

    /** Checks of a running task in one run, POLL_SECONDS apart, before coming back later. */
    private const POLLS = 10;

    private const POLL_SECONDS = 2;

    private const COME_BACK_SECONDS = 30;

    private const DEFAULT_DISK = 'scsi0';

    private const DEFAULT_USER = 'root';

    private const TASK_OK = 'OK';

    private const RUNNING = 'running';

    private const CLONE_TASK = 'clone_task';

    private const TEMPLATE_LABEL = 'template VM ID';

    /** @var array<int, array{ticket: string, CSRFPreventionToken: string}> login tickets by server id, without an API token */
    private array $tickets = [];

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
        $version = $this->api($server, 'get', 'version')['version'] ?? null;

        return ProvisioningResult::ok('Connected to Proxmox VE'.($version ? " {$version}" : '').'.');
    }

    /** The VM's login user is the product's, not one made from the domain. */
    protected function newUsername(Service $service, Server $server): string
    {
        return $this->setting($service, 'user') ?? self::DEFAULT_USER;
    }

    protected function createAccount(Service $service, Server $server): ProvisioningResult
    {
        if (! isset($service->provisioning_data['vmid'])) {
            $this->startClone($service, $server);
        }

        $vmid = (int) $service->provisioning_data['vmid'];

        if (isset($service->provisioning_data[self::CLONE_TASK])) {
            $status = $this->await($server, $service->provisioning_data[self::CLONE_TASK]);

            if ($status === null) {
                return ProvisioningResult::later(self::COME_BACK_SECONDS, "Cloning the template into VM {$vmid}.");
            }

            if ($status !== self::TASK_OK) {
                $this->forgetVm($service);

                return ProvisioningResult::failed("Proxmox couldn't clone the template: {$status}. Run create again to try with a new VM.");
            }

            $this->remember($service, [self::CLONE_TASK => null]);
        }

        return $this->setUp($service, $server, $vmid);
    }

    protected function suspendAccount(Service $service, Server $server): ProvisioningResult
    {
        [$vmid, $vm] = $this->existingVm($service, $server);

        if ($vm['status'] !== self::RUNNING) {
            return ProvisioningResult::ok("VM {$vmid} was already stopped.");
        }

        $this->api($server, 'post', $this->path($vm['node'], $vmid).'/status/shutdown', ['forceStop' => 1, 'timeout' => 60]);

        return ProvisioningResult::ok("Shutting down VM {$vmid}. Proxmox turns it off after a minute if it hasn't shut down.");
    }

    protected function unsuspendAccount(Service $service, Server $server): ProvisioningResult
    {
        [$vmid, $vm] = $this->existingVm($service, $server);

        if ($vm['status'] === self::RUNNING) {
            return ProvisioningResult::ok("VM {$vmid} was already running.");
        }

        return $this->start($server, $vm['node'], $vmid) ?? ProvisioningResult::ok("Started VM {$vmid}.");
    }

    /** Stops and deletes the VM with its disks, and frees its address. */
    protected function terminateAccount(Service $service, Server $server): ProvisioningResult
    {
        $vmid = $service->provisioning_data['vmid'] ?? null;
        $vm = $vmid === null ? null : $this->vm($server, (int) $vmid);

        if ($vm === null) {
            return ProvisioningResult::ok($vmid === null ? 'The service had no VM to remove.' : "VM {$vmid} was already gone.", ['ip' => null]);
        }

        $path = $this->path($vm['node'], (int) $vmid);

        if ($vm['status'] === self::RUNNING) {
            $status = $this->await($server, $this->api($server, 'post', "{$path}/status/stop"));

            if ($status !== self::TASK_OK) {
                return $status === null
                    ? ProvisioningResult::later(self::COME_BACK_SECONDS, "Stopping VM {$vmid}.")
                    : ProvisioningResult::failed("Proxmox couldn't stop VM {$vmid}: {$status}");
            }
        }

        $status = $this->await($server, $this->api($server, 'delete', $path, ['purge' => 1, 'destroy-unreferenced-disks' => 1]));

        return match ($status) {
            self::TASK_OK => ProvisioningResult::ok("Removed VM {$vmid} from {$vm['node']}.", ['ip' => null]),
            null => ProvisioningResult::later(self::COME_BACK_SECONDS, "Removing VM {$vmid}."),
            default => ProvisioningResult::failed("Proxmox couldn't remove VM {$vmid}: {$status}"),
        };
    }

    /** Sets the new product's cores, memory and disk, and restarts a running VM so they apply. */
    protected function changeAccountPackage(Service $service, Server $server): ProvisioningResult
    {
        [$vmid, $vm] = $this->existingVm($service, $server);
        $path = $this->path($vm['node'], $vmid);
        $config = $this->api($server, 'get', "{$path}/config");

        if (filled($config['lock'] ?? null)) {
            return ProvisioningResult::later(self::COME_BACK_SECONDS, "VM {$vmid} is locked ({$config['lock']}).");
        }

        $size = $this->size($service);
        $this->api($server, 'put', "{$path}/config", $size);

        if (($problem = $this->grow($service, $server, $path, $config)) !== null) {
            return $problem;
        }

        if ($vm['status'] !== self::RUNNING) {
            return ProvisioningResult::ok("Set VM {$vmid} to {$size['cores']} cores and {$size['memory']} MB of memory.");
        }

        $this->api($server, 'post', "{$path}/status/reboot");

        return ProvisioningResult::ok("Set VM {$vmid} to {$size['cores']} cores and {$size['memory']} MB of memory, and restarted it so they apply.");
    }

    /** Sets the password inside the running VM, through the QEMU guest agent. */
    protected function changeAccountPassword(Service $service, Server $server): ProvisioningResult
    {
        [$vmid, $vm] = $this->existingVm($service, $server);
        $username = $this->username($service);

        if ($vm['status'] !== self::RUNNING) {
            return ProvisioningResult::failed("VM {$vmid} is stopped. Start it, then run change password again.");
        }

        $this->api($server, 'post', $this->path($vm['node'], $vmid).'/agent/set-user-password', ['username' => $username, 'password' => $service->password]);

        return ProvisioningResult::ok("Changed the password of {$username} on VM {$vmid}.");
    }

    /** Saves the new VM's id, node and address on the service, then starts cloning the template into it. */
    private function startClone(Service $service, Server $server): void
    {
        $templateId = $this->number($service, 'template', self::TEMPLATE_LABEL);
        $template = $this->vm($server, $templateId);

        if ($template === null || ! $template['template']) {
            throw new ModuleError("Proxmox has no template with the VM ID {$templateId}. Check the product's ".self::TEMPLATE_LABEL.'.');
        }

        $node = $this->setting($service, 'node') ?? $template['node'];
        $this->claimAddress($service, $server);
        $vmid = (int) $this->api($server, 'get', 'cluster/nextid');
        $this->remember($service, ['vmid' => $vmid, 'node' => $node]);

        try {
            $task = $this->api($server, 'post', $this->path($template['node'], $templateId).'/clone', array_filter([
                'newid' => $vmid,
                'name' => $this->vmName($service),
                'description' => $this->marker($service).' '.$service->client->fullName(),
                'full' => 1,
                'storage' => $this->setting($service, 'storage'),
                'target' => $node !== $template['node'] ? $node : null,
            ], fn ($value) => $value !== null));
        } catch (ModuleError $e) {
            $this->forgetVm($service); // Proxmox refused, so nothing was made.

            throw $e;
        }

        $this->remember($service, [self::CLONE_TASK => $task]);
    }

    /** Sizes the cloned VM, sets its login and address, and starts it. Safe to run again. */
    private function setUp(Service $service, Server $server, int $vmid): ProvisioningResult
    {
        $vm = $this->vm($server, $vmid);

        if ($vm === null) {
            $this->forgetVm($service);

            return ProvisioningResult::failed("VM {$vmid} isn't on Proxmox. Run create again to clone a new one.");
        }

        $path = $this->path($vm['node'], $vmid);
        $config = $this->api($server, 'get', "{$path}/config");

        // The id was saved before cloning: make sure the VM there is this service's before touching it.
        if (! str_contains((string) ($config['description'] ?? ''), $this->marker($service))) {
            $this->forgetVm($service);

            return ProvisioningResult::failed("VM {$vmid} on Proxmox isn't this service's VM, so it was left alone. Run create again to clone a new one.");
        }

        if (filled($config['lock'] ?? null)) {
            return ProvisioningResult::later(self::COME_BACK_SECONDS, "VM {$vmid} is locked ({$config['lock']}) while Proxmox finishes.");
        }

        $this->api($server, 'put', "{$path}/config", $this->size($service) + array_filter([
            'ciuser' => $service->username,
            'cipassword' => $service->password,
            'ipconfig0' => $this->ipConfig($service, $server),
        ]));

        $problem = $this->grow($service, $server, $path, $config);
        $problem ??= $vm['status'] === self::RUNNING ? null : $this->start($server, $vm['node'], $vmid);
        $ip = $service->provisioning_data['ip'] ?? null;

        return $problem ?? ProvisioningResult::ok(
            "Created VM {$vmid} on {$vm['node']}".($ip ? " with the address {$ip}" : '').'.',
            ['vmid' => $vmid, 'node' => $vm['node']],
        );
    }

    /** Starts a VM. Returns null once it is starting or running, or the failure. */
    private function start(Server $server, string $node, int $vmid): ?ProvisioningResult
    {
        $status = $this->await($server, $this->api($server, 'post', $this->path($node, $vmid).'/status/start'));

        return $status === null || $status === self::TASK_OK ? null : ProvisioningResult::failed("Proxmox couldn't start VM {$vmid}: {$status}");
    }

    /**
     * Grows the disk to the product's size, if it is smaller. Returns null when
     * that is done, or the result to report while it isn't.
     *
     * @param  array<string, mixed>  $config  the VM's config before the change
     */
    private function grow(Service $service, Server $server, string $path, array $config): ?ProvisioningResult
    {
        $size = $this->setting($service, 'disk_size');
        $disk = $this->setting($service, 'disk') ?? self::DEFAULT_DISK;

        if ($size === null) {
            return null;
        }

        $current = self::gigabytes((string) ($config[$disk] ?? ''))
            ?? throw new ModuleError("The VM has no disk {$disk}. Set the product's Disk to the template's disk.");

        if ((float) $size <= $current) {
            return null;
        }

        $status = $this->await($server, $this->api($server, 'put', "{$path}/resize", ['disk' => $disk, 'size' => "{$size}G"]));

        return match ($status) {
            self::TASK_OK => null,
            null => ProvisioningResult::later(self::COME_BACK_SECONDS, "Growing the disk to {$size} GB."),
            default => ProvisioningResult::failed("Proxmox couldn't grow the disk: {$status}"),
        };
    }

    /** Takes the first address in the server's pool that no other service holds. Does nothing without a pool. */
    private function claimAddress(Service $service, Server $server): void
    {
        $pool = array_column($server->addressPool(), 'address');

        if ($pool === [] || isset($service->provisioning_data['ip'])) {
            return;
        }

        // Locking the server makes two new VMs wait for each other here, so they can't share an address.
        DB::transaction(function () use ($service, $server, $pool) {
            Server::whereKey($server->id)->lockForUpdate()->first();
            $taken = $server->services()->whereKeyNot($service->id)->pluck('provisioning_data')->pluck('ip')->filter()->all();
            $free = array_values(array_diff($pool, $taken))[0]
                ?? throw new ModuleError("Every address in the IP pool of {$server->name} is in use. Add more to the server, then run create again.");

            $this->remember($service, ['ip' => $free]);
        });
    }

    /** The cloud-init network line for the service's address, or null to keep the template's. */
    private function ipConfig(Service $service, Server $server): ?string
    {
        $ip = $service->provisioning_data['ip'] ?? null;
        $entry = $ip === null ? null : collect($server->addressPool())->firstWhere('address', $ip);

        if ($entry === null) {
            return null;
        }

        $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $line = ($v6 ? 'ip6=' : 'ip=').$ip.'/'.($entry['prefix'] ?? ($v6 ? 128 : 32));

        return $entry['gateway'] ? $line.($v6 ? ',gw6=' : ',gw=').$entry['gateway'] : $line;
    }

    /** @return array{cores: int, memory: int} */
    private function size(Service $service): array
    {
        return ['cores' => $this->number($service, 'cores', 'CPU cores'), 'memory' => $this->number($service, 'memory', 'memory')];
    }

    private function number(Service $service, string $key, string $label): int
    {
        $value = $this->requiredSetting($service, $key, $label);

        return ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : throw new ModuleError("The {$label} on the product {$service->product->name} must be a whole number above zero.");
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

    /** The VM's name: the service's hostname when it has a usable one. */
    private function vmName(Service $service): string
    {
        $label = (string) $service->label;

        return $label !== '' && strlen($label) <= 63 && filter_var($label, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            ? $label
            : "service-{$service->id}";
    }

    /** Put in the VM's description, to tell the service's VM from any other. */
    private function marker(Service $service): string
    {
        return "[service {$service->id}]";
    }

    /**
     * @return array{int, array{node: string, status: string, template: bool}} the service's VM id and the VM
     */
    private function existingVm(Service $service, Server $server): array
    {
        $vmid = $service->provisioning_data['vmid'] ?? throw new ModuleError('The service has no VM yet. Run create first.');

        return [(int) $vmid, $this->vm($server, (int) $vmid) ?? throw new ModuleError("VM {$vmid} isn't on Proxmox.")];
    }

    /** @return array{node: string, status: string, template: bool}|null the VM as the cluster lists it, wherever it was moved */
    private function vm(Server $server, int $vmid): ?array
    {
        $vm = collect($this->api($server, 'get', 'cluster/resources', ['type' => 'vm']))
            ->first(fn (array $resource) => (int) ($resource['vmid'] ?? 0) === $vmid && ($resource['type'] ?? 'qemu') === 'qemu');

        return $vm === null ? null : ['node' => (string) $vm['node'], 'status' => (string) ($vm['status'] ?? ''), 'template' => (bool) ($vm['template'] ?? false)];
    }

    private function path(string $node, int $vmid): string
    {
        return "nodes/{$node}/qemu/{$vmid}";
    }

    /**
     * Waits for a task: up to POLLS checks. Returns null while it is still
     * running, then its exit status, "OK" or an error.
     */
    private function await(Server $server, mixed $task): ?string
    {
        if (! is_string($task) || ! str_starts_with($task, 'UPID:')) {
            return self::TASK_OK; // Finished without a task.
        }

        $node = explode(':', $task)[1];

        for ($poll = 0; $poll < self::POLLS; $poll++) {
            if ($poll > 0) {
                Sleep::for(self::POLL_SECONDS)->seconds();
            }

            $status = $this->api($server, 'get', "nodes/{$node}/tasks/".rawurlencode($task).'/status');

            if (($status['status'] ?? null) === 'stopped') {
                return (string) ($status['exitstatus'] ?? 'unknown');
            }
        }

        return null;
    }

    /** Saves values in the service's provisioning data at once, for the next run; null removes one. */
    private function remember(Service $service, array $values): void
    {
        $data = array_filter([...$service->provisioning_data ?? [], ...$values], fn ($value) => $value !== null);
        $service->forceFill(['provisioning_data' => $data ?: null])->save();
    }

    private function forgetVm(Service $service): void
    {
        $this->remember($service, ['vmid' => null, 'node' => null, 'ip' => null, self::CLONE_TASK => null]);
    }

    /**
     * Calls the API and returns the reply's data.
     *
     * @param  array<string, mixed>  $params
     */
    private function api(Server $server, string $method, string $path, array $params = []): mixed
    {
        $request = $this->authenticated($server, $method !== 'get')->acceptJson();
        $url = "api2/json/{$path}";

        $response = match ($method) {
            'get' => $request->get($url, $params),
            'delete' => $request->delete($params === [] ? $url : $url.'?'.http_build_query($params)),
            'put' => $request->asForm()->put($url, $params),
            default => $request->asForm()->post($url, $params),
        };

        if (! $response->successful()) {
            throw $this->failure($response, $path);
        }

        return $response->json('data');
    }

    private function authenticated(Server $server, bool $writes): PendingRequest
    {
        $request = $this->http($server);

        if (filled($server->api_token)) {
            return $request->withHeaders(['Authorization' => "PVEAPIToken={$server->username}={$server->api_token}"]);
        }

        $ticket = $this->tickets[$server->id] ??= $this->logIn($server);
        $request->withCookies(['PVEAuthCookie' => $ticket['ticket']], $server->hostname);

        return $writes ? $request->withHeaders(['CSRFPreventionToken' => $ticket['CSRFPreventionToken']]) : $request;
    }

    /** @return array{ticket: string, CSRFPreventionToken: string} */
    private function logIn(Server $server): array
    {
        $response = $this->http($server)->acceptJson()->asForm()
            ->post('api2/json/access/ticket', ['username' => (string) $server->username, 'password' => (string) $server->password]);

        if (! $response->successful() || ! is_string($response->json('data.ticket'))) {
            throw new ModuleError('Proxmox refused the login. Check the server\'s username and password, or use an API token.');
        }

        return $response->json('data');
    }

    private function failure(Response $response, string $path): ModuleError
    {
        $errors = $response->json('errors');
        $detail = trim((string) $response->json('message'))
            ?: (is_array($errors) ? collect($errors)->map(fn ($message, $field) => "{$field}: ".trim((string) $message))->join('; ') : '')
            ?: trim($response->reason());

        return new ModuleError("Proxmox: {$detail} (HTTP {$response->status()} on {$path}).");
    }
}
