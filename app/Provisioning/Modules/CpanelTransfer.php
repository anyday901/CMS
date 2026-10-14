<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Models\Service;
use App\Provisioning\ModuleError;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerAccount;

/**
 * Copies a cPanel account from its old server to the new one with WHM's
 * transfer tool: the new server opens a transfer session that logs in to the
 * old one over SSH as root, packs the account up there and restores it with
 * its files, email, databases and password. The old account is left as it
 * is. A transfer takes minutes, so the session is saved on the service and
 * later runs check on it.
 */
final class CpanelTransfer
{
    /** The WHM transfer session running the copy. */
    private const SESSION = 'transfer_session';

    private const COME_BACK_SECONDS = 60;

    private const FAILED = ['FAILED', 'ABORTED'];

    /** @param  Service  $old  the move's old account */
    public function __construct(private readonly WhmApi $whm, private readonly Service $service, private readonly Service $old, private readonly Server $server) {}

    public function run(): ProvisioningResult
    {
        $session = $this->service->provisioning_data[self::SESSION] ?? null;

        return $session === null ? $this->start() : $this->check((string) $session);
    }

    private function start(): ProvisioningResult
    {
        $from = $this->old->server ?? throw new ModuleError('The old account has no server to copy from.');
        $username = ServerAccount::username($this->old);

        if ($this->whm->account(ServerAccount::username($this->service)) !== null) {
            return ProvisioningResult::failed("{$this->service->username} is already on {$this->server->name}, so nothing was copied over it. Remove it there and run copy account again, or cancel the move.");
        }

        $session = $this->whm->call('create_remote_root_transfer_session', [
            'remote_server_type' => 'cpanel',
            'host' => $from->ip_address ?: $from->hostname,
            'user' => 'root',
            'password' => (string) $from->password,
            'transfer_threads' => 1,
            'restore_threads' => 1,
            'unrestricted_restore' => 0,
            'copy_reseller_privs' => 0,
            'compressed' => 1,
            'unencrypted' => 0,
            'use_backups' => 0,
            'low_priority' => 0,
        ])['transfer_session_id'] ?? throw new ModuleError('WHM didn\'t return a transfer session.');

        $this->whm->call('enqueue_transfer_item', [
            'transfer_session_id' => $session,
            'module' => 'AccountRemoteRoot',
            'user' => $username,
            'localuser' => $this->service->username,
        ]);
        $this->whm->call('start_transfer_session', ['transfer_session_id' => $session]);
        $this->remember((string) $session);

        return ProvisioningResult::later(self::COME_BACK_SECONDS, "Transferring {$username} from {$from->name} to {$this->server->name} (WHM transfer session {$session}).");
    }

    private function check(string $session): ProvisioningResult
    {
        $state = strtoupper((string) ($this->whm->call('get_transfer_session_state', ['transfer_session_id' => $session])['state_name'] ?? ''));
        $username = $this->service->username;

        if ($state === 'COMPLETED') {
            $this->remember(null);

            return $this->whm->account((string) $username) === null
                ? ProvisioningResult::failed("WHM finished transfer session {$session}, but {$username} isn't on {$this->server->name}. See the session in WHM > Transfer Tool for why, then run copy account again.")
                : ProvisioningResult::ok("Copied {$username} to {$this->server->name} with WHM's transfer tool (session {$session}).");
        }

        if (in_array($state, self::FAILED, true)) {
            $this->remember(null);

            return ProvisioningResult::failed("WHM's transfer of {$username} to {$this->server->name} ended ".strtolower($state)." (session {$session}). See WHM > Transfer Tool for why, remove any half-copied account on {$this->server->name}, then run copy account again.");
        }

        return ProvisioningResult::later(self::COME_BACK_SECONDS, "Transferring {$username} to {$this->server->name}".($state !== '' ? ' ('.strtolower($state).')' : '').'.');
    }

    private function remember(?string $session): void
    {
        $data = array_filter([...$this->service->provisioning_data ?? [], self::SESSION => $session], fn ($value) => $value !== null);
        $this->service->forceFill(['provisioning_data' => $data ?: null])->save();
    }
}
