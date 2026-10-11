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

    /**
     * Queues an action. Each action is a new operation, except when staff
     * retry the action that just failed: that keeps its operation number, so
     * the module can tell a retry from a new action (see Service::$provisioningOperation).
     */
    public function queue(Service $service, string $action, bool $retry = false): void
    {
        if ($this->modules->find($service->product->module) === null) {
            return;
        }

        [$sequence, $operation] = DB::transaction(function () use ($service, $action, $retry) {
            $locked = Service::lockForUpdate()->findOrFail($service->id);
            $retryingFailure = $retry
                && $locked->provisioning_status === self::FAILED
                && $locked->provisioning_action === $action
                && $locked->provisioning_operation > 0;

            $locked->forceFill([
                'provisioning_status' => self::PENDING,
                'provisioning_action' => $action,
                'provisioning_error' => null,
                'provisioning_queued' => $locked->provisioning_queued + 1,
            ])->save();

            return [$locked->provisioning_queued, $retryingFailure ? $locked->provisioning_operation : $locked->provisioning_queued];
        });

        RunProvisioningAction::dispatch($service, $action, $sequence, $operation);
    }
}
