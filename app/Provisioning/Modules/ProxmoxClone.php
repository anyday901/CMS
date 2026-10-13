<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use Illuminate\Support\Facades\DB;

/**
 * Makes a service's VM: clones the product's template into a new VM, then
 * sizes it, sets its login and address, and starts it. Copying a big disk
 * can take minutes, so long steps come back later and pick up where they
 * left off: the VM's id is saved on the service before the clone starts.
 */
final class ProxmoxClone
{
    private const CLONE_TASK = 'clone_task';

    private readonly ProxmoxPlan $plan;

    public function __construct(private readonly ProxmoxApi $api, private readonly Service $service, private readonly Server $server)
    {
        $this->plan = new ProxmoxPlan($service);
    }

    public function run(): ProvisioningResult
    {
        if (! isset($this->service->provisioning_data['vmid'])) {
            $this->startClone();
        }

        $vmid = (int) $this->service->provisioning_data['vmid'];

        if (isset($this->service->provisioning_data[self::CLONE_TASK])) {
            $status = $this->api->await($this->service->provisioning_data[self::CLONE_TASK]);

            if ($status === null) {
                return ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Cloning the template into VM {$vmid}.");
            }

            if ($status !== ProxmoxApi::TASK_OK) {
                $this->forget();

                return ProvisioningResult::failed("Proxmox couldn't clone the template: {$status}. Run create again to try with a new VM.");
            }

            $this->remember([self::CLONE_TASK => null]);
        }

        return $this->setUp($vmid);
    }

    /** Saves the new VM's id, node and address on the service, then starts cloning the template into it. */
    private function startClone(): void
    {
        $templateId = $this->plan->template();
        $template = $this->api->vm($templateId);

        if ($template === null || ! $template['template']) {
            throw new ModuleError("Proxmox has no template with the VM ID {$templateId}. Check the product's ".ProxmoxPlan::TEMPLATE_LABEL.'.');
        }

        $node = $this->plan->node() ?? $template['node'];
        $this->claimAddress();
        $vmid = (int) $this->api->call('get', 'cluster/nextid');
        $this->remember(['vmid' => $vmid, 'node' => $node]);

        try {
            $task = $this->api->call('post', ProxmoxApi::path($template['node'], $templateId).'/clone', array_filter([
                'newid' => $vmid,
                'name' => $this->vmName(),
                'description' => $this->marker().' '.$this->service->client->fullName(),
                'full' => 1,
                'storage' => $this->plan->storage(),
                'target' => $node !== $template['node'] ? $node : null,
            ], fn ($value) => $value !== null));
        } catch (ModuleError $e) {
            $this->forget(); // Proxmox refused, so nothing was made.

            throw $e;
        }

        $this->remember([self::CLONE_TASK => $task]);
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
            'ipconfig0' => $this->ipConfig(),
        ]));

        if ($problem === null && $vm['status'] !== ProxmoxApi::RUNNING) {
            $this->api->start($vm['node'], $vmid);
        }

        $ip = $this->service->provisioning_data['ip'] ?? null;

        return $problem ?? ProvisioningResult::ok(
            "Created VM {$vmid} on {$vm['node']}".($ip ? " with the address {$ip}" : '').'.',
            ['vmid' => $vmid, 'node' => $vm['node']],
        );
    }

    /**
     * Why the cloned VM can't be set up yet, or null when it can. The id was
     * saved before cloning, so the VM there must be this service's before
     * anything touches it.
     *
     * @param  array{node: string, status: string, template: bool}|null  $vm
     * @param  array<string, mixed>  $config
     */
    private function problem(int $vmid, ?array $vm, array $config): ?ProvisioningResult
    {
        if ($vm === null || ! str_contains((string) ($config['description'] ?? ''), $this->marker())) {
            $this->forget();

            return ProvisioningResult::failed(($vm === null ? "VM {$vmid} isn't on Proxmox." : "VM {$vmid} on Proxmox isn't this service's VM, so it was left alone.")
                .' Run create again to clone a new one.');
        }

        return filled($config['lock'] ?? null)
            ? ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "VM {$vmid} is locked ({$config['lock']}) while Proxmox finishes.")
            : null;
    }

    /** Takes the first address in the server's pool that no other service holds. Does nothing without a pool. */
    private function claimAddress(): void
    {
        $pool = array_column($this->server->addressPool(), 'address');

        if ($pool === [] || isset($this->service->provisioning_data['ip'])) {
            return;
        }

        // Locking the server makes two new VMs wait for each other here, so they can't share an address.
        DB::transaction(function () use ($pool) {
            Server::whereKey($this->server->id)->lockForUpdate()->first();
            $taken = $this->server->services()->whereKeyNot($this->service->id)->pluck('provisioning_data')->pluck('ip')->filter()->all();
            $free = array_values(array_diff($pool, $taken))[0]
                ?? throw new ModuleError("Every address in the IP pool of {$this->server->name} is in use. Add more to the server, then run create again.");

            $this->remember(['ip' => $free]);
        });
    }

    /** The cloud-init network line for the service's address, or null to keep the template's. */
    private function ipConfig(): ?string
    {
        $ip = $this->service->provisioning_data['ip'] ?? null;
        $entry = $ip === null ? null : collect($this->server->addressPool())->firstWhere('address', $ip);

        if ($entry === null) {
            return null;
        }

        $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        [$address, $gateway, $prefix] = $v6 ? ['ip6=', ',gw6=', 128] : ['ip=', ',gw=', 32];
        $line = $address.$ip.'/'.($entry['prefix'] ?? $prefix);

        return $entry['gateway'] ? $line.$gateway.$entry['gateway'] : $line;
    }

    /** The VM's name: the service's hostname when it has a usable one. */
    private function vmName(): string
    {
        $label = (string) $this->service->label;

        return $label !== '' && strlen($label) <= 63 && filter_var($label, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            ? $label
            : "service-{$this->service->id}";
    }

    /** Put in the VM's description, to tell the service's VM from any other. */
    private function marker(): string
    {
        return "[service {$this->service->id}]";
    }

    /** Saves values in the service's provisioning data at once, for the next run; null removes one. */
    private function remember(array $values): void
    {
        $data = array_filter([...$this->service->provisioning_data ?? [], ...$values], fn ($value) => $value !== null);
        $this->service->forceFill(['provisioning_data' => $data ?: null])->save();
    }

    private function forget(): void
    {
        $this->remember(['vmid' => null, 'node' => null, 'ip' => null, self::CLONE_TASK => null]);
    }
}
