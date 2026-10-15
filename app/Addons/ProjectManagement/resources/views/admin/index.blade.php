@php use App\Addons\ProjectManagement\Duration; use App\Addons\ProjectManagement\Models\Project; @endphp
<x-admin-layout title="Projects">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Projects</h1>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.projects.report') }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Time report</a>
            @can('manage-clients')
                <a href="{{ route('admin.projects.create', array_filter(['client' => $client?->id])) }}" class="rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">New project</a>
            @endcan
        </div>
    </div>

    @include('projects::admin._timer', ['timer' => $timer])

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach (['open' => 'Not finished', 'all' => 'All'] + Project::STATUSES as $value => $label)
            <a href="{{ route('admin.projects.index', array_filter(['status' => $value, 'mine' => $mine ? 1 : null, 'client' => $client?->id])) }}" class="rounded-full px-3 py-1 {{ $show === $value ? 'bg-brand-100 font-semibold text-brand-700' : 'ring-1 ring-ink-300 hover:bg-ink-100' }}">{{ $label }}</a>
        @endforeach
        <span class="mx-1 h-5 w-px bg-ink-200" aria-hidden="true"></span>
        <a href="{{ route('admin.projects.index', array_filter(['status' => $show, 'mine' => $mine ? null : 1, 'client' => $client?->id])) }}" class="rounded-full px-3 py-1 {{ $mine ? 'bg-brand-100 font-semibold text-brand-700' : 'ring-1 ring-ink-300 hover:bg-ink-100' }}">Mine</a>
        @if ($client)
            <span class="rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700">{{ $client->fullName() }}
                <a href="{{ route('admin.projects.index', array_filter(['status' => $show, 'mine' => $mine ? 1 : null])) }}" class="ml-1" title="Show every client's projects">×</a></span>
        @else
            <form method="GET" action="{{ route('admin.projects.index') }}" class="flex items-center gap-1">
                <input type="hidden" name="status" value="{{ $show }}">@if ($mine)<input type="hidden" name="mine" value="1">@endif
                <label class="sr-only" for="client-filter">Client number</label>
                <input id="client-filter" type="number" name="client" min="1" placeholder="Client #" class="w-24 rounded-full px-3 py-1 ring-1 ring-ink-300">
            </form>
        @endif
    </div>

    @if ($projects->isEmpty())
        <p class="rounded-lg border border-dashed border-ink-300 bg-white p-6 text-center text-sm text-ink-500">No projects here yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500">
                    <tr><th class="px-4 py-2">Project</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Owner</th><th class="px-4 py-2">Tasks</th><th class="px-4 py-2 text-right">Time</th><th class="px-4 py-2">Due</th><th class="px-4 py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($projects as $project)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.projects.show', $project) }}" class="text-brand-600">{{ $project->title }}</a></td>
                            <td class="px-4 py-2">@if ($project->client)<a href="{{ route('admin.clients.show', $project->client) }}" class="text-brand-600">{{ $project->client->fullName() }}</a>@else<span class="text-ink-400">Internal</span>@endif</td>
                            <td class="px-4 py-2">{{ $project->owner?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $project->done_tasks_count }} of {{ $project->tasks_count }} done</td>
                            <td class="whitespace-nowrap px-4 py-2 text-right">{{ Duration::format((int) $project->minutes) }}</td>
                            <td class="whitespace-nowrap px-4 py-2 {{ $project->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $project->due_on?->format('M j, Y') ?? '—' }}@if ($project->isOverdue()) · overdue @endif</td>
                            <td class="px-4 py-2"><x-status-badge :status="$project->badge()" class="whitespace-nowrap">{{ $project->statusLabel() }}</x-status-badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $projects->links() }}</div>
    @endif
</x-admin-layout>
