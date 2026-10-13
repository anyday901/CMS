<?php

namespace App\Jobs;

use App\Models\AccountMove;
use App\Models\Activity;
use App\Notifications\StaffAlert;
use App\Provisioning\AccountMover;
use App\Provisioning\ModuleRegistry;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes the old account of a finished move with its old module, as that
 * module's terminate. Like other module actions, a failure isn't retried on
 * its own: staff look, then finish the move again.
 */
class RemoveOldAccount implements ShouldQueue
{
    use Queueable;

    public function __construct(public AccountMove $move) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("account-move-{$this->move->id}"))->releaseAfter(15)->expireAfter(900)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(ModuleRegistry $modules, AccountMover $mover): void
    {
        $move = $this->move->fresh(['service.client', 'fromServer', 'fromProduct']);

        if ($move?->status === AccountMove::REMOVING) {
            $this->remove($move, $modules->find($move->from_module), $mover);
        }
    }

    private function remove(AccountMove $move, ?ProvisioningModule $module, AccountMover $mover): void
    {
        $where = $move->fromServer ? " from {$move->fromServer->name}" : '';

        if ($module === null) {
            $mover->done($move, "Finished moving {$move->service->description()}; its old module is gone, so remove the old account{$where} by hand.");

            return;
        }

        $result = $this->terminate($module, $move);

        match (true) {
            $result->retryAfter !== null => $this->release($result->retryAfter),
            $result->ok => $mover->done($move, "Finished moving {$move->service->description()}: removed the old account{$where}".($result->message ? ". {$result->message}" : '.')),
            default => $this->markFailed($move, (string) $result->message),
        };
    }

    private function terminate(ProvisioningModule $module, AccountMove $move): ProvisioningResult
    {
        try {
            return $module->terminate($move->oldAccount());
        } catch (Throwable $e) {
            Log::error('Removing a moved account failed', ['move' => $move->id, 'exception' => $e]);

            return ProvisioningResult::failed($e->getMessage() ?: $e::class);
        }
    }

    /** Called when the job gives up after an hour of waiting. */
    public function failed(?Throwable $e): void
    {
        $move = $this->move->fresh('service.client');

        if ($move?->status === AccountMove::REMOVING) {
            $this->markFailed($move, 'Removing the old account didn\'t finish within an hour. Finish the move again when the old server is reachable.');
        }
    }

    private function markFailed(AccountMove $move, string $error): void
    {
        $move->update(['status' => AccountMove::FAILED, 'error' => $error]);
        Activity::record("Removing the old account of {$move->service->description()} failed: {$error}", $move->service);
        StaffAlert::send(
            'manage-clients',
            "Move not finished: {$move->service->description()}",
            ["The old account of {$move->service->description()} ({$move->service->client->fullName()}) couldn't be removed.", "Error: {$error}", 'Fix the cause, then finish the move again from the service page.'],
            'Open the service',
            route('admin.services.show', $move->service),
        );
    }
}
