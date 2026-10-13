<?php

namespace App\Jobs;

use App\Models\AccountMove;
use App\Models\Activity;
use App\Models\Service;
use App\Notifications\StaffAlert;
use App\Provisioning\AccountMover;
use App\Provisioning\CopiesAccounts;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\Provisioner;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use App\Provisioning\ServerPicker;
use App\Provisioning\UsesServers;
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
 * safer for staff to look at and run again than to repeat blindly. A module
 * waiting on the other system (ProvisioningResult::later()) is run again
 * after the wait it asks for.
 */
class RunProvisioningAction implements ShouldQueue
{
    use Queueable;

    private const WAIT_SECONDS = 15;

    /** @param  int|null  $operation  Defaults to the sequence; a retry of a failed action passes that action's operation. */
    public function __construct(public Service $service, public string $action, public int $sequence, public ?int $operation = null)
    {
        $this->operation ??= $sequence;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("provisioning-{$this->service->id}"))->releaseAfter(self::WAIT_SECONDS)->expireAfter(900)];
    }

    /** Hours to keep waiting for earlier actions or the module: a day for copying an account, which can be big. */
    private function hours(): int
    {
        return $this->action === Provisioner::COPY ? 24 : 1;
    }

    /** Keeps waiting for earlier actions, or for the module to finish, for up to an hour (a day for a copy). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours($this->hours());
    }

    public function handle(ModuleRegistry $modules, ServerPicker $servers): void
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

        $result = $this->run($service, $module, $servers);

        if ($result->retryAfter !== null) {
            $this->release($result->retryAfter); // The other system is still working on it.

            return;
        }

        $this->finish($service, $module, $result);
    }

    /** Called when the job gives up after an hour of waiting. */
    public function failed(?Throwable $e): void
    {
        $service = $this->service->fresh();

        if ($service && $this->sequence > $service->provisioning_finished) {
            $within = $this->hours() === 1 ? 'an hour' : "{$this->hours()} hours";
            $error = "The {$this->action} action didn't finish within {$within}, waiting for an earlier action or for the module. Run it again when the module is reachable.";
            $service->forceFill([
                'provisioning_status' => Provisioner::FAILED,
                'provisioning_error' => $error,
                'provisioning_finished' => $this->sequence,
                'provisioning_operation' => $this->operation,
            ])->save();
            $this->alert($service, $error);
        }
    }

    private function run(Service $service, ProvisioningModule $module, ServerPicker $servers): ProvisioningResult
    {
        $service->provisioningOperation = $this->operation;
        $working = $service->password;

        // A new password staff saved waits in new_password until the module has set it, so a failure keeps the one that works.
        if ($this->action === 'change_password' && filled($service->new_password)) {
            $service->password = $service->new_password;
        }

        $result = $this->call($service, $module, $servers);

        if ($this->action === 'change_password') {
            $service->forceFill($result->ok ? ['new_password' => null] : ['password' => $working]);
        }

        return $result;
    }

    private function call(Service $service, ProvisioningModule $module, ServerPicker $servers): ProvisioningResult
    {
        try {
            if ($module instanceof UsesServers && $this->action === 'create' && ($problem = $servers->assign($service, $module)) !== null) {
                return ProvisioningResult::failed($problem);
            }

            if ($this->action === Provisioner::COPY) {
                return $this->copy($service, $module);
            }

            return $module->{Provisioner::method($this->action)}($service);
        } catch (Throwable $e) {
            Log::error('Provisioning action failed', ['service' => $service->id, 'action' => $this->action, 'exception' => $e]);

            return ProvisioningResult::failed($e->getMessage() ?: $e::class);
        }
    }

    /** Copies the account of the service's open move to its new server. */
    private function copy(Service $service, ProvisioningModule $module): ProvisioningResult
    {
        $move = $service->openMove()->first();

        return $move?->copy_data && $move->status === AccountMove::CREATING && $module instanceof CopiesAccounts
            ? $module->copyAccount($service, $move)
            : ProvisioningResult::failed('The service has no move waiting for its account to be copied.');
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
            'provisioning_operation' => $this->operation,
        ])->save();

        Activity::record(
            Provisioner::label($this->action)." on {$module->label()} ".($result->ok ? 'done' : 'failed').' for '.$service->description()
                .($result->message ? ": {$result->message}" : ''),
            $service,
        );

        if (! $result->ok) {
            $this->alert($service, $result->message);
        } elseif (in_array($this->action, ['create', Provisioner::COPY], true)) {
            app(AccountMover::class)->created($service);
        }
    }

    private function alert(Service $service, ?string $error): void
    {
        StaffAlert::send(
            'manage-clients',
            'Provisioning failed: '.str_replace('_', ' ', $this->action)." for {$service->description()}",
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
