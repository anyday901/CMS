<?php

namespace App\Addons\ProjectManagement\Http\Controllers;

use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\Task;
use App\Addons\ProjectManagement\ProjectHooks;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** A project's tasks: add, edit, tick off and reorder. Any staff member can, like the to-do list. */
class TaskController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $task = $project->tasks()->create($this->validated($request));
        Activity::record("Added task \"{$task->title}\" to project \"{$project->title}\"", $project);

        return $this->backToTasks($project)->with('status', 'Task added.');
    }

    public function edit(Task $task): View
    {
        return view('projects::admin.task', ['task' => $task->load('project'), 'staff' => User::orderBy('name')->get()]);
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->change($task, $this->validated($request));

        return $this->backToTasks($task->project_id)->with('status', 'Task saved.');
    }

    /** The quick tick: done, or back to do. */
    public function status(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Task::STATUSES))]]);
        $this->change($task, $data);

        return back()->with('status', $task->isDone() ? 'Task done. Nice work.' : 'Task updated.');
    }

    /** Moves a task one place up or down the list. */
    public function move(Request $request, Task $task): RedirectResponse
    {
        $up = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'] === 'up';

        DB::transaction(function () use ($task, $up) {
            // Number the list 1, 2, 3... first, so two tasks never share a place.
            $tasks = $task->project->tasks()->get()->values();
            $index = $tasks->search(fn (Task $other) => $other->is($task));
            $swap = $up ? $index - 1 : $index + 1;

            if ($swap >= 0 && $swap < $tasks->count()) {
                $tasks[$index] = $tasks->get($swap);
                $tasks[$swap] = $task;
            }

            foreach ($tasks as $position => $each) {
                $each->forceFill(['position' => $position + 1])->saveQuietly();
            }
        });

        return $this->backToTasks($task->project_id);
    }

    public function destroy(Task $task): RedirectResponse
    {
        $task->delete();
        Activity::record("Deleted task \"{$task->title}\" from project \"{$task->project->title}\"", $task->project);

        return $this->backToTasks($task->project_id)->with('status', 'Task deleted. Time logged on it stays on the project.');
    }

    /** Saves the task, and tells subscribers when it has just been finished. */
    private function change(Task $task, array $data): void
    {
        $wasDone = $task->isDone();
        $task->update($data);
        Activity::record(! $wasDone && $task->isDone()
            ? "Finished task \"{$task->title}\" on project \"{$task->project->title}\""
            : "Updated task \"{$task->title}\" on project \"{$task->project->title}\"", $task->project);

        if (! $wasDone && $task->isDone()) {
            ProjectHooks::taskCompleted($task);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'status' => ['nullable', Rule::in(array_keys(Task::STATUSES))],
            'due_on' => ['nullable', 'date'],
        ], [], ['user_id' => 'staff member', 'due_on' => 'due date']);

        return ['status' => $data['status'] ?? Task::TODO] + $data;
    }

    /** The project page, scrolled to its tasks. */
    private function backToTasks(Project|int $project): RedirectResponse
    {
        return redirect()->to(route('admin.projects.show', $project).'#tasks');
    }
}
