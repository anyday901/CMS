<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Models\Service;
use App\Notifications\StaffAlert;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one module action. Actions for a service run one at a time and in the
 * order they were queued, so a slow create can't land after a terminate. A
 * module error is not retried: a half-finished action on another system is
 * safer for staff to look at and run again than to repeat blindly.
 */
class RunProvisioningAction implements ShouldQueue
{
    use Queueable;

    private const WAIT_SECONDS = 15;

    public function __construct(public Service $service, public string $action, public int $sequence) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("provisioning-{$this->service->id}"))->releaseAfter(self::WAIT_SECONDS)->expireAfter(900)];
    }

    /** Keeps waiting for earlier actions for up to an hour. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(ModuleRegistry $modules): void
    {
        $service = $this->service->fresh('product');
        $module = $modules->find($service?->product->module);

        if ($service === null || $module === null || $this->sequence <= $service->provisioning_finished) {
            return; // Gone, module removed, or already handled.
        }

        if ($this->sequence > $service->provisioning_finished + 1) {
            $this->release(self::WAIT_SECONDS); // An earlier action hasn't finished yet.

            return;
        }

        $this->finish($service, $module, $this->run($service, $module));
    }

    /** Called when the job gives up waiting for an earlier action. */
    public function failed(?Throwable $e): void
    {
        $service = $this->service->fresh();

        if ($service && $this->sequence > $service->provisioning_finished) {
            $error = "The {$this->action} action timed out waiting for an earlier action. Run it again when the module is reachable.";
            $service->forceFill([
                'provisioning_status' => Provisioner::FAILED,
                'provisioning_error' => $error,
                'provisioning_finished' => $this->sequence,
            ])->save();
            $this->alert($service, $error);
        }
    }

    private function run(Service $service, ProvisioningModule $module): ProvisioningResult
    {
        try {
            return $module->{$this->action}($service);
        } catch (Throwable $e) {
            Log::error('Provisioning action failed', ['service' => $service->id, 'action' => $this->action, 'exception' => $e]);

            return ProvisioningResult::failed($e->getMessage() ?: $e::class);
        }
    }

    private function finish(Service $service, ProvisioningModule $module, ProvisioningResult $result): void
    {
        $service->forceFill([
            // Stays pending while later actions are queued behind this one.
            'provisioning_status' => match (true) {
                $this->sequence < $service->provisioning_queued => Provisioner::PENDING,
                $result->ok => Provisioner::DONE,
                default => Provisioner::FAILED,
            },
            'provisioning_action' => $this->action,
            'provisioning_error' => $result->ok ? null : $result->message,
            'provisioned_at' => $result->ok ? now() : $service->provisioned_at,
            'provisioning_data' => array_merge($service->provisioning_data ?? [], $result->data),
            'provisioning_finished' => $this->sequence,
        ])->save();

        Activity::record(
            ucfirst($this->action)." on {$module->label()} ".($result->ok ? 'done' : 'failed').' for '.$service->description()
                .($result->message ? ": {$result->message}" : ''),
            $service,
        );

        if (! $result->ok) {
            $this->alert($service, $result->message);
        }
    }

    private function alert(Service $service, ?string $error): void
    {
        StaffAlert::send(
            'manage-clients',
            "Provisioning failed: {$this->action} for {$service->description()}",
            array_values(array_filter([
                "The {$this->action} action failed for {$service->description()} ({$service->client->fullName()}).",
                $error ? "Error: {$error}" : null,
                'Fix the cause, then run the action again from the service page.',
            ])),
            'Open the service',
            route('admin.services.show', $service),
        );
    }
}
