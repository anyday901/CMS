<?php

namespace App\Provisioning\Modules;

use App\Models\Server;
use App\Provisioning\ServerHealth;
use App\Provisioning\Usage;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;

/** What the Proxmox module reports about its servers: each VM's use, and each node's health. */
trait ProxmoxReports
{
    /**
     * Each VM's or container's disk and traffic from the cluster's resource
     * list, in one call. Proxmox counts traffic (in and out) since the VM
     * started, so that is reported as a counter. VMs only report disk use
     * through a guest agent; without one their disk size is all there is.
     */
    public function usage(Server $server, Collection $services): array
    {
        $vms = collect(ProxmoxApi::for($server)->call('get', 'cluster/resources', ['type' => 'vm']))->keyBy(fn (array $vm) => (int) ($vm['vmid'] ?? 0));
        $usage = [];

        foreach ($services as $service) {
            $vm = $vms->get((int) ($service->provisioning_data['vmid'] ?? 0));

            if ($vm !== null) {
                $usage[$service->id] = new Usage(
                    diskMb: Usage::fromBytes($vm['disk'] ?? null),
                    diskLimitMb: Usage::fromBytes($vm['maxdisk'] ?? null),
                    trafficCounter: (int) ($vm['netin'] ?? 0) + (int) ($vm['netout'] ?? 0),
                );
            }
        }

        return $usage;
    }

    /** Each node's CPU and memory use and uptime; a node that isn't online is a problem. */
    public function health(Server $server): ServerHealth
    {
        $facts = [];
        $problems = [];

        foreach (collect(ProxmoxApi::for($server)->call('get', 'cluster/resources', ['type' => 'node']))->sortBy('node') as $node) {
            $name = (string) ($node['node'] ?? '?');

            if (($node['status'] ?? 'online') !== 'online') {
                $problems[] = "Node {$name} is ".($node['status'] ?? 'offline').'.';
                $facts[$name] = (string) ($node['status'] ?? 'offline');

                continue;
            }

            $memory = ($node['maxmem'] ?? 0) > 0 ? (int) round(100 * ($node['mem'] ?? 0) / $node['maxmem']) : null;
            $facts[$name] = 'CPU '.(int) round(100 * (float) ($node['cpu'] ?? 0)).'%'
                .($memory !== null ? ", memory {$memory}%" : '')
                .(isset($node['uptime']) ? ', up '.CarbonInterval::seconds((int) $node['uptime'])->cascade()->forHumans(['parts' => 2, 'short' => true]) : '');
        }

        return new ServerHealth($facts, $problems);
    }
}
