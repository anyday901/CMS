<?php

namespace App\Addons\ProjectManagement\Http\Controllers;

use App\Addons\ProjectManagement\Models\Project;
use App\Addons\ProjectManagement\Models\Task;
use App\Addons\ProjectManagement\Models\TimeEntry;
use App\Addons\ProjectManagement\Models\Timer;
use App\Addons\ProjectManagement\ProjectHooks;
use App\Addons\ProjectManagement\ProjectInvoicer;
use App\Addons\ProjectManagement\ProjectManagementAddon;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Admin > Projects: the list, each project's page, and invoicing its time. */
class ProjectController extends Controller
{
    /** Filters for the list: not finished yet (the default), every project, or one status. */
    private const SHOW = ['open', 'all', Project::PLANNED, Project::ACTIVE, Project::ON_HOLD, Project::COMPLETED, Project::CANCELLED];

    public function index(Request $request): View
    {
        $show = in_array($request->query('status'), self::SHOW, true) ? $request->query('status') : 'open';
        $mine = $request->boolean('mine');
        $client = $request->integer('client') ? Client::find($request->integer('client')) : null;

        $projects = Project::with(['client', 'owner'])
            ->withCount(['tasks', 'tasks as done_tasks_count' => fn ($query) => $query->where('status', Task::DONE)])
            ->withSum('timeEntries as minutes', 'minutes')
            ->when($show === 'open', fn ($query) => $query->open())
            ->when(! in_array($show, ['open', 'all'], true), fn ($query) => $query->where('status', $show))
            ->when($mine, fn ($query) => $query->where('user_id', $request->user()->id))
            ->when($client, fn ($query) => $query->where('client_id', $client->id))
            ->orderByRaw('due_on is null')->orderBy('due_on')->latest('id')
            ->paginate(50)->withQueryString();

        return view('projects::admin.index', [
            'projects' => $projects,
            'show' => $show,
            'mine' => $mine,
            'client' => $client,
            'timer' => Timer::with('project')->where('user_id', $request->user()->id)->first(),
        ]);
    }

    public function create(Request $request, ProjectManagementAddon $addon): View
    {
        $client = $request->integer('client') ? Client::find($request->integer('client')) : null;

        return view('projects::admin.form', [
            'project' => new Project(['client_id' => $client?->id, 'user_id' => $request->user()->id, 'hourly_rate' => $addon->defaultRate()]),
            'staff' => User::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = Project::create($this->validated($request));
        Activity::record("Created project \"{$project->title}\"", $project);
        ProjectHooks::created($project);

        return redirect()->route('admin.projects.show', $project)->with('status', 'Project created. Add its tasks below.');
    }

    public function show(Request $request, Project $project): View
    {
        $project->load(['client', 'owner', 'tasks.user', 'invoices' => fn ($query) => $query->latest('id')]);
        $entries = $project->timeEntries()->with(['user', 'task', 'invoice'])->latest('worked_on')->latest('id')->get();

        return view('projects::admin.show', [
            'project' => $project,
            'entries' => $entries,
            'unbilledAmount' => $project->timeEntries()->unbilled()->get()->sum(fn (TimeEntry $entry) => $entry->amount()),
            'staff' => User::orderBy('name')->get(),
            'timer' => Timer::with(['project', 'task'])->where('user_id', $request->user()->id)->first(),
            'newEntry' => new TimeEntry(['worked_on' => today(), 'billable' => $project->client_id !== null, 'user_id' => $request->user()->id]),
        ]);
    }

    public function edit(Project $project): View
    {
        return view('projects::admin.form', ['project' => $project, 'staff' => User::orderBy('name')->get()]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $wasCompleted = $project->status === Project::COMPLETED;
        $project->update($this->validated($request));
        Activity::record("Updated project \"{$project->title}\"", $project);

        if (! $wasCompleted && $project->status === Project::COMPLETED) {
            ProjectHooks::completed($project);
        }

        return redirect()->route('admin.projects.show', $project)->with('status', 'Project saved.');
    }

    /** Deletes the project with its tasks and time. Invoices already made stay as they are. */
    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();
        Activity::record("Deleted project \"{$project->title}\"", null, [], $project->client);

        return redirect()->route('admin.projects.index')->with('status', 'Project deleted.');
    }

    /** "Invoice unbilled time": a draft invoice for the client, ready to check and publish. */
    public function invoice(Project $project, ProjectInvoicer $invoicer): RedirectResponse
    {
        try {
            $invoice = $invoicer->invoice($project);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('admin.invoices.show', $invoice)
            ->with('status', 'Draft invoice created from the unbilled time on '.$project->title.'. Check it, then publish it to send it to the client.');
    }

    /** Links one of the client's invoices to the project, such as a deposit. */
    public function link(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:50']]);
        $invoice = Invoice::where('number', ltrim($data['number'], '#'))->first();

        if ($invoice === null || ($project->client_id && $invoice->client_id !== $project->client_id)) {
            throw ValidationException::withMessages(['number' => 'There\'s no invoice with that number for this project\'s client.']);
        }

        $project->invoices()->syncWithoutDetaching([$invoice->id]);
        Activity::record("Linked invoice #{$invoice->number} to project \"{$project->title}\"", $project);

        return back()->with('status', "Invoice #{$invoice->number} linked.");
    }

    public function unlink(Project $project, Invoice $invoice): RedirectResponse
    {
        $project->invoices()->detach($invoice->id);

        return back()->with('status', "Invoice #{$invoice->number} is no longer linked to this project. Any time billed on it stays billed.");
    }

    /** Time logged in a date range, by staff member and by project. */
    public function report(Request $request): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? today()->startOfMonth());
        $to = CarbonImmutable::parse($data['to'] ?? today());

        $entries = TimeEntry::with(['project.client', 'user'])
            ->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->when($data['user'] ?? null, fn ($query, $user) => $query->where('user_id', $user))
            ->get();

        return view('projects::admin.report', [
            'from' => $from,
            'to' => $to,
            'user' => $data['user'] ?? null,
            'staff' => User::orderBy('name')->get(),
            'total' => $entries->sum('minutes'),
            'billable' => $entries->where('billable', true)->sum('minutes'),
            'byStaff' => $entries->groupBy(fn (TimeEntry $entry) => $entry->user?->name ?? 'Former staff')->map->sum('minutes')->sortDesc(),
            'byProject' => $entries->groupBy('project_id')->map(fn ($group) => [
                'project' => $group->first()->project,
                'minutes' => $group->sum('minutes'),
                'billable' => $group->where('billable', true)->sum('minutes'),
                'amount' => $group->sum(fn (TimeEntry $entry) => $entry->amount()),
            ])->sortByDesc('minutes'),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(array_keys(Project::STATUSES))],
            'due_on' => ['nullable', 'date'],
            'hourly_rate' => ['nullable', Money::rule()],
        ], [], ['client_id' => 'client', 'user_id' => 'owner', 'due_on' => 'due date']);

        $data['hourly_rate'] = filled($data['hourly_rate'] ?? null) ? Money::parse($data['hourly_rate']) : 0;

        return $data;
    }
}
