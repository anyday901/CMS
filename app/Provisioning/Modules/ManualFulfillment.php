<?php

namespace App\Provisioning\Modules;

use App\Models\Activity;
use App\Models\FulfillmentTask;
use App\Models\Service;
use App\Notifications\StaffAlert;
use App\Provisioning\ProvisioningModule;
use App\Provisioning\ProvisioningResult;

/**
 * For products set up by hand: each action opens a task for staff with the
 * product's checklist for that action, and emails staff about it.
 */
class ManualFulfillment implements ProvisioningModule
{
    private const DEFAULT_CHECKLISTS = [
        'create' => ['Set up the service', 'Send the client their details'],
        'suspend' => ['Suspend the service'],
        'unsuspend' => ['Unsuspend the service'],
        'terminate' => ['Remove the service and its data'],
    ];

    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual fulfillment';
    }

    public function configFields(): array
    {
        $help = 'One step per line. Leave empty for a single default step.';

        return [
            'create_checklist' => ['label' => 'Set-up checklist', 'type' => 'textarea', 'help' => $help],
            'suspend_checklist' => ['label' => 'Suspend checklist', 'type' => 'textarea', 'help' => $help],
            'unsuspend_checklist' => ['label' => 'Unsuspend checklist', 'type' => 'textarea', 'help' => $help],
            'terminate_checklist' => ['label' => 'Terminate checklist', 'type' => 'textarea', 'help' => $help],
        ];
    }

    public function create(Service $service): ProvisioningResult
    {
        return $this->open($service, 'create');
    }

    public function suspend(Service $service): ProvisioningResult
    {
        return $this->open($service, 'suspend');
    }

    public function unsuspend(Service $service): ProvisioningResult
    {
        return $this->open($service, 'unsuspend');
    }

    public function terminate(Service $service): ProvisioningResult
    {
        return $this->open($service, 'terminate');
    }

    private function open(Service $service, string $action): ProvisioningResult
    {
        // Running the action again while its task is still open adds nothing.
        $existing = FulfillmentTask::open()->where('service_id', $service->id)->where('action', $action)->first();

        if ($existing) {
            return ProvisioningResult::ok("Task #{$existing->id} is already open for staff.");
        }

        $task = FulfillmentTask::create([
            'service_id' => $service->id,
            'action' => $action,
            'checklist' => array_map(fn (string $text) => ['text' => $text, 'done' => false], $this->steps($service, $action)),
        ]);
        Activity::record("Opened task #{$task->id}: {$task->title()}", $service);

        StaffAlert::send(
            'manage-clients',
            "New task: {$task->title()}",
            ["{$task->title()} for {$service->client->fullName()} needs doing by hand.", count($task->checklist).' step(s) on the checklist.'],
            'Open the task',
            route('admin.tasks.show', $task),
        );

        return ProvisioningResult::ok("Task #{$task->id} opened for staff.");
    }

    /** @return list<string> */
    private function steps(Service $service, string $action): array
    {
        $lines = preg_split('/\R/', (string) ($service->product->module_config["{$action}_checklist"] ?? ''));
        $steps = array_values(array_filter(array_map('trim', $lines), fn (string $line) => $line !== ''));

        return $steps ?: self::DEFAULT_CHECKLISTS[$action];
    }
}
