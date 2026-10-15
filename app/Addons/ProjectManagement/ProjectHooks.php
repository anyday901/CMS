<?php

namespace App\Addons\ProjectManagement;

use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\Task;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Invoice;
use App\Support\Money;

/** The add-on's own events, which webhooks, chat channels and plugins can subscribe to. */
class ProjectHooks
{
    public const CREATED = 'project.created';

    public const COMPLETED = 'project.completed';

    public const TASK_COMPLETED = 'project.task_completed';

    public const INVOICED = 'project.invoiced';

    /** @return array<string, string> event key => label */
    public static function events(): array
    {
        return [
            self::CREATED => 'New project',
            self::COMPLETED => 'Project completed',
            self::TASK_COMPLETED => 'Project task completed',
            self::INVOICED => 'Project time invoiced',
        ];
    }

    public static function created(Project $project): void
    {
        self::fire(self::CREATED, $project, "New project: {$project->title}".($project->client ? " for {$project->client->fullName()}" : ''));
    }

    public static function completed(Project $project): void
    {
        self::fire(self::COMPLETED, $project, "Project completed: {$project->title}".($project->client ? " for {$project->client->fullName()}" : ''));
    }

    public static function taskCompleted(Task $task): void
    {
        $project = $task->project;
        self::fire(self::TASK_COMPLETED, $project, "Task done on {$project->title}: {$task->title}", ['task' => [
            'id' => $task->id,
            'title' => $task->title,
            'user_id' => $task->user_id,
            'due_on' => $task->due_on?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ]]);
    }

    public static function invoiced(Project $project, Invoice $invoice, int $entries): void
    {
        self::fire(self::INVOICED, $project, "Draft invoice #{$invoice->number} for {$project->title}: ".Money::format($invoice->total, $invoice->currency), [
            'invoice_id' => $invoice->id,
            'invoice_total' => (int) $invoice->total,
            'time_entries' => $entries,
        ]);
    }

    /** @return array<string, mixed> the project's main fields, as webhooks receive them */
    public static function payload(Project $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'client_id' => $project->client_id,
            'user_id' => $project->user_id,
            'status' => $project->status,
            'due_on' => $project->due_on?->toDateString(),
            'hourly_rate' => (int) $project->hourly_rate,
            'currency' => $project->currency(),
            'completed_at' => $project->completed_at?->toIso8601String(),
            'created_at' => $project->created_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $extra */
    private static function fire(string $key, Project $project, string $summary, array $extra = []): void
    {
        app(Hooks::class)->fire(new HookEvent($key, ['project' => self::payload($project)] + $extra, $summary, route('admin.projects.show', $project), $project->client_id));
    }
}
