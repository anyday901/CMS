<?php

namespace App\Monitoring;

use App\Models\Activity;
use App\Models\Server;
use App\Notifications\StaffAlert;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\ReportsServerHealth;
use App\Provisioning\UsesServers;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Server status monitoring: checks that each monitored server's module
 * port and its other listed ports answer, and asks modules that report
 * their health (ReportsServerHealth) how the server is doing. A server is
 * down when the module's port doesn't answer, and in warning when another
 * port or a node doesn't. A change counts once two checks in a row agree,
 * so one dropped connection doesn't wake anyone; then staff are emailed and
 * the outage is recorded until the server recovers.
 */
class ServerMonitor
{
    /** Checks in a row that must agree before the status changes. */
    private const CONFIRM = 2;

    public function __construct(private ModuleRegistry $modules, private PortProbe $probe) {}

    /** @return Collection<int, Server> the servers checked */
    public function checkAll(): Collection
    {
        return Server::where('monitor', true)->orderBy('name')->get()->each(fn (Server $server) => $this->check($server));
    }

    public function check(Server $server): Server
    {
        $module = $this->modules->find($server->module);
        $mainPort = $server->port ?: ($module instanceof UsesServers ? $module->defaultPort($server->secure) : null);
        $ports = [];

        foreach (array_unique(array_filter([$mainPort, ...$server->monitoredPorts()])) as $port) {
            $ports[(string) $port] = $this->probe->open($server->hostname, $port);
        }

        $closed = array_keys(array_filter($ports, fn (?int $ms) => $ms === null));
        $facts = [];
        $problems = $closed === [] ? [] : ['Port'.(count($closed) > 1 ? 's ' : ' ').implode(', ', $closed).(count($closed) > 1 ? " don't" : " doesn't").' answer.'];

        if ($mainPort !== null && $ports[(string) $mainPort] === null) {
            $status = Server::DOWN;
        } else {
            if ($module instanceof ReportsServerHealth) {
                try {
                    $health = $module->health($server);
                    $facts = $health->facts;
                    $problems = [...$problems, ...$health->problems];
                } catch (Throwable $e) {
                    $problems[] = 'Couldn\'t read its status: '.($e->getMessage() ?: $e::class);
                }
            }

            $status = $problems === [] ? Server::UP : Server::WARNING;
        }

        $this->record($server, $status, $problems === [] ? null : implode(' ', $problems), $ports, $facts);

        return $server;
    }

    /**
     * @param  array<string, ?int>  $ports
     * @param  array<string, string>  $facts
     */
    private function record(Server $server, string $status, ?string $detail, array $ports, array $facts): void
    {
        $current = $server->status ?? Server::UP;
        $server->forceFill(['status_ports' => $ports, 'status_load' => $facts ?: null, 'status_checked_at' => now()]);

        if ($status === $current) {
            $server->forceFill(['status' => $status, 'status_detail' => $detail, 'status_pending' => null, 'status_misses' => 0, 'status_since' => $server->status_since ?? now()])->save();

            return;
        }

        // Only checks in a row that found the same new status count towards it.
        $misses = $server->status_pending === $status ? $server->status_misses + 1 : 1;

        if ($misses < self::CONFIRM) {
            $server->forceFill(['status_pending' => $status, 'status_misses' => $misses])->save();

            return;
        }

        $was = $server->status;
        $server->outages()->whereNull('ended_at')->update(['ended_at' => now()]);

        if ($status !== Server::UP) {
            $server->outages()->create(['status' => $status, 'detail' => $detail, 'started_at' => now()]);
        }

        $server->forceFill(['status' => $status, 'status_detail' => $detail, 'status_pending' => null, 'status_misses' => 0, 'status_since' => now()])->save();
        Activity::record("Server {$server->name} is ".strtolower($server->statusLabel()).($detail ? ": {$detail}" : ''), $server);
        $this->alert($server, $was, $detail);
    }

    private function alert(Server $server, ?string $was, ?string $detail): void
    {
        $subject = match ($server->status) {
            Server::DOWN => "Server down: {$server->name}",
            Server::WARNING => "Server warning: {$server->name}",
            default => "Server back up: {$server->name}",
        };

        // A server first seen up is no news.
        if ($was === null && $server->status === Server::UP) {
            return;
        }

        StaffAlert::send('manage-settings', $subject, array_values(array_filter([
            "{$server->name} ({$server->hostname}) is ".strtolower($server->statusLabel()).' as of '.now()->format('M j, Y g:ia').'.',
            $detail,
        ])), 'Open the servers page', route('admin.servers.index'));
    }
}
