<?php

namespace App\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads disk and bandwidth use from every server whose module reports it
 * (ReportsUsage) and keeps it on each service, one row per month.
 */
class UsageCollector
{
    private const BYTES_PER_MB = 1024 * 1024;

    public function __construct(private ModuleRegistry $modules) {}

    /**
     * @return array{services: int, errors: list<string>} how many services were read, and the servers that couldn't be
     */
    public function collect(CarbonInterface $now, ?Server $only = null): array
    {
        $read = 0;
        $errors = [];

        foreach (($only ? collect([$only]) : Server::orderBy('id')->get()) as $server) {
            $module = $this->modules->find($server->module);
            $services = $server->services()->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])->whereNotNull('provisioned_at')->get();

            if (! $module instanceof ReportsUsage || $services->isEmpty()) {
                continue;
            }

            try {
                $readings = $module->usage($server, $services);
            } catch (Throwable $e) {
                Log::warning('Reading usage failed', ['server' => $server->id, 'exception' => $e]);
                $errors[] = "{$server->name}: ".($e->getMessage() ?: $e::class);

                continue;
            }

            foreach ($readings as $serviceId => $usage) {
                $this->record($services->find($serviceId), $usage, $now);
                $read++;
            }
        }

        return ['services' => $read, 'errors' => $errors];
    }

    /** Saves a reading on the service's row for $now's month. */
    public function record(Service $service, Usage $usage, CarbonInterface $now): ServiceUsage
    {
        $period = $now->copy()->startOfMonth();
        $row = $service->usages()->whereDate('period', $period)->first()
            ?? new ServiceUsage(['service_id' => $service->id, 'period' => $period]);
        $bandwidth = $usage->bandwidthMb;

        if ($usage->trafficCounter !== null) {
            // The month's traffic is what the counter grew by between readings; a smaller reading means it started again.
            $previous = $row->counter_bytes ?? $service->usages()->whereDate('period', '<', $period)->orderByDesc('period')->value('counter_bytes');
            $grown = match (true) {
                $previous === null => 0,
                $usage->trafficCounter >= $previous => $usage->trafficCounter - $previous,
                default => $usage->trafficCounter,
            };
            $row->counted_bytes = (int) $row->counted_bytes + $grown;
            $row->counter_bytes = $usage->trafficCounter;
            $bandwidth = intdiv($row->counted_bytes, self::BYTES_PER_MB);
        }

        $row->fill([
            'disk_mb' => $usage->diskMb,
            'disk_limit_mb' => $usage->diskLimitMb,
            'bandwidth_mb' => $bandwidth,
            'bandwidth_limit_mb' => $usage->bandwidthLimitMb,
            'read_at' => $now,
        ])->save();

        return $row;
    }
}
