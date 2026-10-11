<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\FulfillmentTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Manual fulfillment tasks: open ones first, oldest first, so nothing waits too long. */
class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $done = $request->query('status') === 'done';

        $tasks = FulfillmentTask::query()
            ->with(['service.client', 'service.product', 'completedBy'])
            ->when($done, fn ($query) => $query->whereNotNull('completed_at')->latest('completed_at'))
            ->when(! $done, fn ($query) => $query->open()->oldest())
            ->paginate(50)
            ->withQueryString();

        return view('admin.tasks.index', compact('tasks', 'done'));
    }

    public function show(FulfillmentTask $task): View
    {
        $task->load(['service.client', 'service.product', 'completedBy']);

        return view('admin.tasks.show', compact('task'));
    }

    /** Saves ticked steps and notes; "Mark done" also closes the task once every step is ticked. */
    public function update(Request $request, FulfillmentTask $task): RedirectResponse
    {
        abort_unless($task->isOpen(), 422, 'This task is already done.');

        $data = $request->validate([
            'done' => ['array'],
            'done.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $ticked = array_map('intval', $data['done'] ?? []);
        $task->checklist = collect($task->checklist)
            ->map(fn (array $step, int $i) => ['text' => $step['text'], 'done' => in_array($i, $ticked, true)])
            ->all();
        $task->notes = $data['notes'] ?? null;

        if ($request->boolean('complete')) {
            if ($task->remaining() > 0) {
                $task->save();

                throw ValidationException::withMessages(['done' => 'Tick every step before marking the task done.']);
            }

            $task->completed_at = now();
            $task->completed_by = $request->user()->id;
            $task->save();
            Activity::record("Completed task #{$task->id}: {$task->title()}", $task->service);

            return redirect()->route('admin.tasks.index')->with('status', 'Task done.');
        }

        $task->save();

        return back()->with('status', 'Task saved.');
    }
}
