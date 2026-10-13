<?php

namespace App\Provisioning\Modules;

use App\Models\AccountMove;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use Illuminate\Support\Facades\DB;

/**
 * Makes a service's VM or container. Long steps come back later and pick up
 * where they left off, from what is saved on the service: its id is saved
 * before Proxmox starts making it, and the task while it runs.
 */
abstract class ProxmoxBuild
{
    /** The running Proxmox task that makes the VM or container. */
    protected const TASK = 'build_task';

    /** A VM id to use again, kept by a reinstall so the new VM gets the old one's. */
    public const REUSE_ID = 'reuse_vmid';

    protected readonly ProxmoxPlan $plan;

    public function __construct(protected readonly ProxmoxApi $api, protected readonly Service $service, protected readonly Server $server)
    {
        $this->plan = new ProxmoxPlan($service);
    }

    /** Picks up the build where an earlier run left it. */
    abstract public function run(): ProvisioningResult;

    /** What the VM or container is called in messages. */
    abstract protected function noun(): string;

    /**
     * Waits for the build task saved by an earlier run. Returns null once it
     * worked (or when there is none), or the result to report.
     */
    protected function awaitTask(int $vmid): ?ProvisioningResult
    {
        $task = $this->service->provisioning_data[self::TASK] ?? null;

        if ($task === null) {
            return null;
        }

        $status = $this->api->await($task);

        if ($status === null) {
            return ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, $this->waiting($vmid));
        }

        if ($status !== ProxmoxApi::TASK_OK) {
            $this->forget();

            return ProvisioningResult::failed($this->taskFailed($status));
        }

        $this->remember([self::TASK => null]);

        return null;
    }

    /** Shown while the build task runs. */
    abstract protected function waiting(int $vmid): string;

    /** The failure when the build task ends with an error. */
    abstract protected function taskFailed(string $status): string;

    /** The id for the new VM or container: the one a reinstall kept, or the cluster's next free one. */
    protected function newId(): int
    {
        return (int) ($this->service->provisioning_data[self::REUSE_ID] ?? $this->api->call('get', 'cluster/nextid'));
    }

    /**
     * Why the VM or container at $vmid can't be set up yet, or null when it
     * can. The id was saved before Proxmox made it, so it must be this
     * service's before anything touches it.
     *
     * @param  array{node: string, status: string, template: bool, type: string}|null  $vm
     * @param  array<string, mixed>  $config
     */
    protected function problem(int $vmid, ?array $vm, array $config): ?ProvisioningResult
    {
        if ($vm === null || ! str_contains((string) ($config['description'] ?? ''), $this->marker())) {
            $this->forget();
            $noun = ucfirst($this->noun());

            return ProvisioningResult::failed(($vm === null ? "{$noun} {$vmid} isn't on Proxmox." : "{$noun} {$vmid} on Proxmox isn't this service's, so it was left alone.")
                .' Run create again to make a new one.');
        }

        return filled($config['lock'] ?? null)
            ? ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, ucfirst($this->noun())." {$vmid} is locked ({$config['lock']}) while Proxmox finishes.")
            : null;
    }

    /**
     * Takes the first address in the server's pool that no other service
     * holds, nor an old account still waiting to be removed after a move.
     * Does nothing without a pool, or when the service has one.
     */
    protected function claimAddress(): void
    {
        $pool = array_column($this->server->addressPool(), 'address');

        if ($pool === [] || isset($this->service->provisioning_data['ip'])) {
            return;
        }

        // Locking the server makes two new VMs wait for each other here, so they can't share an address.
        DB::transaction(function () use ($pool) {
            Server::whereKey($this->server->id)->lockForUpdate()->first();
            $taken = $this->server->services()->whereKeyNot($this->service->id)->pluck('provisioning_data')->pluck('ip')
                ->merge(AccountMove::open()->where('from_server_id', $this->server->id)->pluck('from_data')->pluck('ip'))
                ->filter()->all();
            $free = array_values(array_diff($pool, $taken))[0]
                ?? throw new ModuleError("Every address in the IP pool of {$this->server->name} is in use. Add more to the server, then run create again.");

            $this->remember(['ip' => $free]);
        });
    }

    /**
     * The service's address from the pool, as Proxmox writes it: "ip=" and
     * "gw=" (ip6 and gw6 for IPv6) with the prefix length. Null without one.
     */
    protected function addressSettings(): ?string
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

    /** The service's hostname when it has a usable one, otherwise service-{id}. */
    protected function hostname(): string
    {
        $label = (string) $this->service->label;

        return $label !== '' && strlen($label) <= 63 && filter_var($label, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            ? $label
            : "service-{$this->service->id}";
    }

    /** Put in the description, to tell the service's VM or container from any other. */
    protected function marker(): string
    {
        return "[service {$this->service->id}]";
    }

    protected function description(): string
    {
        return $this->marker().' '.$this->service->client->fullName();
    }

    /** The result once the VM or container is set up and running. */
    protected function created(int $vmid, string $node): ProvisioningResult
    {
        $ip = $this->service->provisioning_data['ip'] ?? null;

        return ProvisioningResult::ok(
            'Created '.$this->noun()." {$vmid} on {$node}".($ip ? " with the address {$ip}" : '').'.',
            ['vmid' => $vmid, 'node' => $node],
        );
    }

    /** Saves values in the service's provisioning data at once, for the next run; null removes one. */
    protected function remember(array $values): void
    {
        $data = array_filter([...$this->service->provisioning_data ?? [], ...$values], fn ($value) => $value !== null);
        $this->service->forceFill(['provisioning_data' => $data ?: null])->save();
    }

    protected function forget(): void
    {
        $this->remember(['vmid' => null, 'node' => null, 'ip' => null, self::TASK => null, self::REUSE_ID => null]);
    }
}
