<?php

namespace App\Provisioning\Modules;

use App\Models\AccountMove;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;

/**
 * Moves a service's VM or container to its new server with Proxmox's own
 * migration. Inside one cluster it is migrated to the new server's node
 * (live for a running VM, with a restart for a running container), and as
 * the VM itself moves there is no old one to remove afterwards. To another
 * cluster it is copied with remote migration, which logs in to the new
 * server with its API token, and the old one is kept, stopped, until staff
 * finish the move. Either way it keeps its disks, network settings,
 * address and password. Migrating takes a while, so the task is saved on the
 * service and later runs check on it.
 */
final class ProxmoxMigration
{
    private const TASK = 'migrate_task';

    /** The VM id and node it is migrating to. */
    private const TARGET = 'migrate_target';

    /** Where remote migration puts the disks when the product doesn't say. */
    private const DEFAULT_STORAGE = 'local-lvm';

    public function __construct(private readonly ProxmoxApi $from, private readonly ProxmoxApi $to, private readonly Service $service, private readonly AccountMove $move) {}

    public function run(): ProvisioningResult
    {
        if (! isset($this->service->provisioning_data[self::TASK])) {
            $result = $this->start();

            if ($result !== null) {
                return $result;
            }
        }

        return $this->check();
    }

    /** Starts the migration and saves its task. Returns a result when there was nothing to start. */
    private function start(): ?ProvisioningResult
    {
        $vmid = (int) ($this->move->from_data['vmid'] ?? throw new ModuleError('The old account has nothing on Proxmox to copy.'));
        $vm = $this->from->vm($vmid) ?? throw new ModuleError("{$vmid} isn't on {$this->move->fromServer->name} any more.");
        $source = $this->from->cluster();
        $target = $this->to->cluster();
        $path = ProxmoxApi::path($vm['node'], $vmid, $vm['type']);
        $running = $vm['status'] === ProxmoxApi::RUNNING;
        $live = $vm['type'] === ProxmoxApi::QEMU ? ['online' => (int) $running] : ['restart' => (int) $running];

        if ($source['name'] !== null && $source['name'] === $target['name']) {
            // The VM itself moves, so finishing the move must never remove it.
            $this->move->update(['remove_old' => false]);

            if ($vm['node'] === $target['node']) {
                return $this->moved($vmid, $target['node'], ProxmoxApi::name($vmid, $vm)." was already on {$target['node']}.");
            }

            $params = ['target' => $target['node'], ...$live] + ($vm['type'] === ProxmoxApi::QEMU ? ['with-local-disks' => 1] : []);
            $task = $this->from->call('post', "{$path}/migrate", $params);
            $this->remember([self::TASK => $task, self::TARGET => "{$vmid}@{$target['node']}"]);

            return null;
        }

        $server = $this->service->server;

        if (blank($server->api_token)) {
            throw new ModuleError("{$server->name} is in another Proxmox cluster. Copying there uses Proxmox's remote migration, which needs an API token on {$server->name}. Add one, or move the account without copying.");
        }

        $newId = (int) $this->to->call('get', 'cluster/nextid');
        $plan = new ProxmoxPlan($this->service);
        $endpoint = array_filter([
            "host={$server->hostname}",
            "apitoken=PVEAPIToken={$server->username}={$server->api_token}",
            ($fingerprint = $this->to->fingerprint($target['node'])) ? "fingerprint={$fingerprint}" : null,
            'port='.($server->port ?: 8006),
        ]);

        $task = $this->from->call('post', "{$path}/remote_migrate", [
            'target-endpoint' => implode(',', $endpoint),
            'target-vmid' => $newId,
            'target-storage' => $plan->storage() ?? self::DEFAULT_STORAGE,
            'target-bridge' => $plan->bridge(),
            'delete' => 0,
            ...$live,
        ]);
        $this->remember([self::TASK => $task, self::TARGET => "{$newId}@{$target['node']}"]);

        return null;
    }

    private function check(): ProvisioningResult
    {
        $data = $this->service->provisioning_data;
        [$vmid, $node] = explode('@', (string) $data[self::TARGET], 2);
        $old = (int) $this->move->from_data['vmid'];
        $status = $this->from->await($data[self::TASK]);

        if ($status === null) {
            return ProvisioningResult::later(ProxmoxApi::COME_BACK_SECONDS, "Migrating {$old} to {$node}.");
        }

        $this->remember([self::TASK => null, self::TARGET => null]);

        if ($status !== ProxmoxApi::TASK_OK) {
            return ProvisioningResult::failed("Proxmox couldn't migrate {$old} to {$node}: {$status}. Run copy account again, or cancel the move.");
        }

        return $this->moved((int) $vmid, $node, (int) $vmid === $old
            ? "Migrated {$old} to {$node}."
            : "Copied {$old} to {$this->service->server->name} as {$vmid} on {$node}, with remote migration. The old one is stopped on {$this->move->fromServer->name} until the move is finished.");
    }

    /** The result once the VM or container is on the new server, keeping its address. */
    private function moved(int $vmid, string $node, string $message): ProvisioningResult
    {
        $ip = $this->move->from_data['ip'] ?? null;

        return ProvisioningResult::ok($message.($ip ? " It keeps the address {$ip}." : ''), array_filter(['vmid' => $vmid, 'node' => $node, 'ip' => $ip]));
    }

    private function remember(array $values): void
    {
        $data = array_filter([...$this->service->provisioning_data ?? [], ...$values], fn ($value) => $value !== null);
        $this->service->forceFill(['provisioning_data' => $data ?: null])->save();
    }
}
