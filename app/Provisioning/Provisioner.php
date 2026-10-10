<?php

namespace App\Provisioning;

use App\Jobs\RunProvisioningAction;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/** Queues module actions for services whose product uses a provisioning module. */
class Provisioner
{
    public const ACTIONS = ['create', 'suspend', 'unsuspend', 'terminate'];

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(private ModuleRegistry $modules) {}

    public function queue(Service $service, string $action): void
    {
        if ($this->modules->find($service->product->module) === null) {
            return;
        }

        $sequence = DB::transaction(function () use ($service, $action) {
            $locked = Service::lockForUpdate()->findOrFail($service->id);
            $locked->forceFill([
                'provisioning_status' => self::PENDING,
                'provisioning_action' => $action,
                'provisioning_error' => null,
                'provisioning_queued' => $locked->provisioning_queued + 1,
            ])->save();

            return $locked->provisioning_queued;
        });

        RunProvisioningAction::dispatch($service, $action, $sequence);
    }
}
