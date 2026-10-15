<?php

namespace App\Addons\ProjectManagement\Http\Controllers;

use App\Addons\ProjectManagement\Duration;
use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\TimeEntry;
use App\Addons\ProjectManagement\Models\Timer;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Time staff log on projects, typed in or from the start/stop timer. */
class TimeEntryController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $entry = $project->timeEntries()->make($this->validated($request, $project));
        $entry->user_id ??= $request->user()->id;
        $entry->save();
        Activity::record('Logged '.Duration::format($entry->minutes)." on project \"{$project->title}\"", $project);

        return redirect()->to(route('admin.projects.show', $project).'#time')->with('status', 'Logged '.Duration::format($entry->minutes).'.');
    }

    public function edit(TimeEntry $entry): View
    {
        $this->ensureUnbilled($entry);

        return view('projects::admin.time', ['entry' => $entry->load('project.tasks'), 'staff' => User::orderBy('name')->get()]);
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->ensureUnbilled($entry);
        $data = $this->validated($request, $entry->project);
        $data['user_id'] ??= $entry->user_id;
        // Time from a cancelled invoice is unbilled; changing it drops the old link.
        $entry->forceFill(['invoice_id' => null, 'invoice_item_id' => null])->update($data);
        Activity::record("Changed time logged on project \"{$entry->project->title}\"", $entry->project);

        return redirect()->to(route('admin.projects.show', $entry->project_id).'#time')->with('status', 'Time saved.');
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        $this->ensureUnbilled($entry);
        $entry->delete();
        Activity::record('Deleted '.Duration::format($entry->minutes)." logged on project \"{$entry->project->title}\"", $entry->project);

        return redirect()->to(route('admin.projects.show', $entry->project_id).'#time')->with('status', 'Time deleted.');
    }

    /** Starts the signed-in staff member's timer on this project, logging any timer already running. */
    public function start(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'task_id' => ['nullable', 'integer', Rule::exists('pm_tasks', 'id')->where('project_id', $project->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $logged = DB::transaction(function () use ($request, $project, $data) {
            $logged = $this->finish($request->user());
            Timer::create(['user_id' => $request->user()->id, 'project_id' => $project->id, 'started_at' => now()] + $data);

            return $logged;
        });

        return back()->with('status', 'Timer started.'.($logged ? ' Your last timer was stopped and its '.Duration::format($logged->minutes).' logged.' : ''));
    }

    /** Stops the timer and logs its time. */
    public function stop(Request $request): RedirectResponse
    {
        $entry = DB::transaction(fn () => $this->finish($request->user()));

        return $entry
            ? redirect()->to(route('admin.projects.show', $entry->project_id).'#time')->with('status', 'Timer stopped. Logged '.Duration::format($entry->minutes).'.')
            : back()->with('status', 'Your timer isn\'t running.');
    }

    /** Throws the running timer away without logging anything. */
    public function discard(Request $request): RedirectResponse
    {
        Timer::where('user_id', $request->user()->id)->delete();

        return back()->with('status', 'Timer discarded.');
    }

    /** Ends this staff member's timer, if one is running, and logs it. */
    private function finish(User $user): ?TimeEntry
    {
        $timer = Timer::with('project')->where('user_id', $user->id)->lockForUpdate()->first();

        if ($timer === null) {
            return null;
        }

        $entry = $timer->project->timeEntries()->create([
            'task_id' => $timer->task_id,
            'user_id' => $user->id,
            'worked_on' => $timer->started_at->toDateString(),
            'minutes' => min(Duration::MAX_MINUTES, $timer->minutes()),
            'description' => $timer->description,
            'billable' => $timer->project->client_id !== null,
            'rate' => $timer->project->hourly_rate,
        ]);
        $timer->delete();
        Activity::record('Logged '.Duration::format($entry->minutes)." on project \"{$timer->project->title}\" from the timer", $timer->project);

        return $entry;
    }

    /** Billed time is fixed until its invoice line is removed or the invoice is cancelled. */
    private function ensureUnbilled(TimeEntry $entry): void
    {
        if ($entry->isBilled()) {
            throw ValidationException::withMessages(['entry' => "This time is billed on invoice #{$entry->invoice->number}. Remove its line or cancel the invoice to change it."]);
        }
    }

    private function validated(Request $request, Project $project): array
    {
        $data = $request->validate([
            'worked_on' => ['required', 'date'],
            'time' => ['required', 'string', 'max:20', Duration::rule()],
            'task_id' => ['nullable', 'integer', Rule::exists('pm_tasks', 'id')->where('project_id', $project->id)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'description' => ['nullable', 'string', 'max:255'],
            'billable' => ['nullable', 'boolean'],
            'rate' => ['nullable', Money::rule()],
        ], [], ['worked_on' => 'date', 'task_id' => 'task', 'user_id' => 'staff member']);

        return [
            'worked_on' => $data['worked_on'],
            'minutes' => Duration::parse($data['time']),
            'task_id' => $data['task_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'description' => $data['description'] ?? null,
            'billable' => $request->boolean('billable'),
            'rate' => filled($data['rate'] ?? null) ? Money::parse($data['rate']) : $project->hourly_rate,
        ];
    }
}
