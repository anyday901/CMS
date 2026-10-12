<?php

namespace App\Provisioning;

use App\Jobs\RunProvisioningAction;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Queues module actions for services whose product uses a provisioning module. */
class Provisioner
{
    public const ACTIONS = ['create', 'suspend', 'unsuspend', 'terminate', 'change_package'];

    public const PENDING = 'pending';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public function __construct(private ModuleRegistry $modules) {}

    /** Changing package is optional for modules; the other actions aren't. */
    public static function supports(ProvisioningModule $module, string $action): bool
    {
        return $action !== 'change_package' || $module instanceof ChangesPackage;
    }

    /** The module method for an action, e.g. changePackage. */
    public static function method(string $action): string
    {
        return Str::camel($action);
    }

    /** "Change package" */
    public static function label(string $action): string
    {
        return ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Queues an action. Each action is a new operation, except when staff
     * retry the action that just failed: that keeps its operation number, so
     * the module can tell a retry from a new action (see Service::$provisioningOperation).
     */
    public function queue(Service $service, string $action, bool $retry = false): void
    {
        $module = $this->modules->find($service->product->module);

        if ($module === null || ! self::supports($module, $action)) {
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
