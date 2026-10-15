<?php

namespace App\Addons\ProjectManagement\Http\Controllers;

use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\Task;
use App\Addons\ProjectManagement\ProjectManagementAddon;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's projects in the portal, when "Show projects to clients" is
 * on: status, tasks and due dates, and time spent if that's shown too.
 * Task notes and rates are never shown.
 */
class PortalProjectController extends Controller
{
    public function __construct(private ProjectManagementAddon $addon) {}

    public function index(Request $request): View
    {
        abort_unless($this->addon->setting('portal'), 404);

        $projects = Project::where('client_id', $request->user('client')->id)
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($query) => $query->where('status', Task::DONE)])
            ->orderByRaw("status in ('completed', 'cancelled')")->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')
            ->get();

        return view('projects::portal.index', ['projects' => $projects]);
    }

    public function show(Request $request, int $project): View
    {
        abort_unless($this->addon->setting('portal'), 404);

        $project = Project::where('client_id', $request->user('client')->id)->with('tasks')->findOrFail($project);
        $showTime = (bool) $this->addon->setting('portal_time');

        return view('projects::portal.show', [
            'project' => $project,
            'showTime' => $showTime,
            'entries' => $showTime ? $project->timeEntries()->with('task')->latest('worked_on')->latest('id')->get() : collect(),
        ]);
    }
}
