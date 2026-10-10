<?php

namespace App\Provisioning;

use App\Jobs\RunProvisioningAction;
use App\Models\Service;

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

        $service->forceFill([
            'provisioning_status' => self::PENDING,
            'provisioning_action' => $action,
            'provisioning_error' => null,
        ])->save();

        RunProvisioningAction::dispatch($service, $action);
    }
}
