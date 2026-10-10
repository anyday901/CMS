<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Service;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\ProvisioningResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one module action. It is tried once: a half-finished create on a
 * remote system is safer for staff to look at and retry than to repeat blindly.
 */
class RunProvisioningAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Service $service, public string $action) {}

    public function handle(ModuleRegistry $modules): void
    {
        $service = $this->service->fresh('product');
        $module = $modules->find($service?->product->module);

        if ($service === null || $module === null) {
            return;
        }

        try {
            $result = $module->{$this->action}($service);
        } catch (Throwable $e) {
            Log::error('Provisioning action failed', ['service' => $service->id, 'action' => $this->action, 'exception' => $e]);
            $result = ProvisioningResult::failed($e->getMessage() ?: $e::class);
        }

        $service->forceFill([
            'provisioning_status' => $result->ok ? Provisioner::DONE : Provisioner::FAILED,
            'provisioning_action' => $this->action,
            'provisioning_error' => $result->ok ? null : $result->message,
            'provisioned_at' => $result->ok ? now() : $service->provisioned_at,
            'provisioning_data' => array_merge($service->provisioning_data ?? [], $result->data),
        ])->save();

        Activity::record(
            ucfirst($this->action)." on {$module->label()} ".($result->ok ? 'done' : 'failed').' for '.$service->description()
                .($result->message ? ": {$result->message}" : ''),
            $service,
        );
    }
}
