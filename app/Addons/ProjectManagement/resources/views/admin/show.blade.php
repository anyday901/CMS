@php use App\Addons\ProjectManagement\Duration; use App\Addons\ProjectManagement\Models\Task; use App\Support\Money; @endphp
<x-admin-layout :title="$project->title">
    <p class="mb-2 text-sm"><a href="{{ route('admin.projects.index') }}" class="text-brand-600">Projects</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $project->title }}</h1>
        <x-status-badge :status="$project->badge()">{{ $project->statusLabel() }}</x-status-badge>
        @can('manage-clients')
            <a href="{{ route('admin.projects.edit', $project) }}" class="ml-auto rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Edit</a>
        @endcan
    </div>

    @include('projects::admin._timer', ['timer' => $timer])

    <div class="mb-8 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-4">
        <div><div class="text-ink-500">Client</div>@if ($project->client)<a href="{{ route('admin.clients.show', $project->client) }}" class="text-brand-600">{{ $project->client->fullName() }}</a>@else Internal @endif</div>
        <div><div class="text-ink-500">Owner</div>{{ $project->owner?->name ?? 'No one' }}</div>
        <div><div class="text-ink-500">Due</div><span class="{{ $project->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $project->due_on?->format('M j, Y') ?? 'No date' }}@if ($project->isOverdue()) · overdue @endif</span></div>
        <div><div class="text-ink-500">Hourly rate</div>{{ Money::format($project->hourly_rate, $project->currency()) }}</div>
        @if ($project->description)<div class="md:col-span-4"><div class="text-ink-500">Description</div>{!! nl2br(e($project->description)) !!}</div>@endif
    </div>

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="space-y-8 lg:col-span-2">
            <section id="tasks">
                <h2 class="mb-3 text-lg font-semibold">Tasks <span class="text-sm font-normal text-ink-500">{{ $project->tasks->where('status', Task::DONE)->count() }} of {{ $project->tasks->count() }} done</span></h2>
                @if ($project->tasks->isEmpty())
                    <p class="rounded-lg border border-dashed border-ink-300 bg-white p-4 text-center text-sm text-ink-500">No tasks yet. Add the first one below.</p>
                @else
                    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                        <table class="min-w-full text-sm">
                            <thead class="bg-ink-50 text-left text-ink-500">
                                <tr><th class="w-8 px-4 py-2"><span class="sr-only">Done</span></th><th class="px-4 py-2">Task</th><th class="px-4 py-2">For</th><th class="px-4 py-2">Due</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"><span class="sr-only">Order</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-ink-100">
                                @foreach ($project->tasks as $task)
                                    <tr class="{{ $task->isDone() ? 'text-ink-500' : '' }}">
                                        <td class="px-4 py-2 align-top">
                                            <form method="POST" action="{{ route('admin.projects.tasks.status', $task) }}">
                                                @csrf @method('PUT')
                                                <input type="hidden" name="status" value="{{ $task->isDone() ? Task::TODO : Task::DONE }}">
                                                <button title="{{ $task->isDone() ? 'Mark not done' : 'Mark done' }}" class="flex size-5 items-center justify-center rounded ring-1 {{ $task->isDone() ? 'bg-brand-600 text-white ring-brand-600' : 'ring-ink-400 hover:bg-brand-50' }}">
                                                    @if ($task->isDone())✓@endif<span class="sr-only">{{ $task->isDone() ? 'Mark not done' : 'Mark done' }}: {{ $task->title }}</span>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="px-4 py-2">
                                            <a href="{{ route('admin.projects.tasks.edit', $task) }}" class="{{ $task->isDone() ? 'line-through' : 'text-brand-600' }}">{{ $task->title }}</a>
                                            @if ($task->notes)<div class="text-ink-500">{{ Str::limit($task->notes, 120) }}</div>@endif
                                        </td>
                                        <td class="px-4 py-2">{{ $task->user?->name ?? 'Anyone' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 {{ $task->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $task->due_on?->format('M j, Y') ?? '—' }}</td>
                                        <td class="px-4 py-2"><x-status-badge :status="$task->isDone() ? 'done' : ($task->status === Task::TODO ? 'open' : 'on_hold')" class="whitespace-nowrap">{{ $task->statusLabel() }}</x-status-badge></td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">
                                            @foreach (['up' => '↑', 'down' => '↓'] as $direction => $arrow)
                                                <form method="POST" action="{{ route('admin.projects.tasks.move', $task) }}" class="inline">
                                                    @csrf @method('PUT')
                                                    <input type="hidden" name="direction" value="{{ $direction }}">
                                                    <button title="Move {{ $direction }}" class="px-1 text-ink-500 hover:text-ink-900">{{ $arrow }}<span class="sr-only">Move {{ $task->title }} {{ $direction }}</span></button>
                                                </form>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <details class="mt-3 rounded-lg border border-ink-200 bg-white p-4 text-sm" @if ($errors->has('title')) open @endif>
                    <summary class="cursor-pointer font-medium text-brand-600">Add a task</summary>
                    <form method="POST" action="{{ route('admin.projects.tasks.store', $project) }}" class="mt-3 space-y-3">
                        @csrf
                        @include('projects::admin._task_fields', ['task' => new Task(['user_id' => auth()->id()])])
                        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add task</button>
                    </form>
                </details>
            </section>

            <section id="time">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-semibold">Time <span class="text-sm font-normal text-ink-500">{{ Duration::format($entries->sum('minutes')) }} logged, {{ Duration::format($entries->where('billable', true)->sum('minutes')) }} billable</span></h2>
                    @if ($project->client && $unbilledAmount > 0)
                        @can('manage-billing')
                            <form method="POST" action="{{ route('admin.projects.invoice', $project) }}" class="ml-auto" onsubmit="return confirm('Create a draft invoice for this client with the unbilled time?')">
                                @csrf
                                <button class="rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Invoice unbilled time ({{ Money::format($unbilledAmount, $project->currency()) }})</button>
                            </form>
                        @endcan
                    @endif
                </div>
                @if ($entries->isEmpty())
                    <p class="rounded-lg border border-dashed border-ink-300 bg-white p-4 text-center text-sm text-ink-500">No time logged yet.</p>
                @else
                    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                        <table class="min-w-full text-sm">
                            <thead class="bg-ink-50 text-left text-ink-500">
                                <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Who</th><th class="px-4 py-2">What</th><th class="px-4 py-2 text-right">Time</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Billing</th></tr>
                            </thead>
                            <tbody class="divide-y divide-ink-100">
                                @foreach ($entries as $entry)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-2">{{ $entry->worked_on->format('M j, Y') }}</td>
                                        <td class="px-4 py-2">{{ $entry->user?->name ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            @if ($entry->isBilled()){{ $entry->description ?: '—' }}@else<a href="{{ route('admin.projects.time.edit', $entry) }}" class="text-brand-600">{{ $entry->description ?: 'No description' }}</a>@endif
                                            @if ($entry->task)<div class="text-ink-500">{{ $entry->task->title }}</div>@endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">{{ Duration::format($entry->minutes) }}</td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">{{ $entry->billable ? Money::format($entry->amount(), $project->currency()) : '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2">
                                            @if (! $entry->billable)
                                                <span class="text-ink-500">Not billable</span>
                                            @elseif ($entry->isBilled())
                                                <a href="{{ route('admin.invoices.show', $entry->invoice) }}" class="text-brand-600">#{{ $entry->invoice->number }}</a> <x-status-badge :status="$entry->invoice->status" />
                                            @else
                                                <x-status-badge status="pending">Unbilled</x-status-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section id="invoices">
                <h2 class="mb-3 text-lg font-semibold">Invoices</h2>
                @if ($project->invoices->isEmpty())
                    <p class="text-sm text-ink-500">No invoices for this project yet.</p>
                @else
                    <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                        <table class="min-w-full text-sm">
                            <thead class="bg-ink-50 text-left text-ink-500">
                                <tr><th class="px-4 py-2">Invoice</th><th class="px-4 py-2">Date</th><th class="px-4 py-2 text-right">Total</th><th class="px-4 py-2 text-right">Balance</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"><span class="sr-only">Unlink</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-ink-100">
                                @foreach ($project->invoices as $invoice)
                                    <tr>
                                        <td class="px-4 py-2"><a href="{{ route('admin.invoices.show', $invoice) }}" class="text-brand-600">#{{ $invoice->number }}</a></td>
                                        <td class="px-4 py-2">{{ $invoice->issue_date->format('M j, Y') }}</td>
                                        <td class="px-4 py-2 text-right">{{ Money::format($invoice->total, $invoice->currency) }}</td>
                                        <td class="px-4 py-2 text-right">{{ Money::format(max(0, $invoice->balance()), $invoice->currency) }}</td>
                                        <td class="px-4 py-2"><x-status-badge :status="$invoice->status" /></td>
                                        <td class="px-4 py-2 text-right">
                                            @can('manage-billing')
                                                <form method="POST" action="{{ route('admin.projects.invoices.unlink', [$project, $invoice]) }}">
                                                    @csrf @method('DELETE')
                                                    <button class="text-ink-500 hover:underline">Unlink</button>
                                                </form>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @can('manage-billing')
                    <form method="POST" action="{{ route('admin.projects.invoices.link', $project) }}" class="mt-3 flex flex-wrap items-end gap-2 text-sm">
                        @csrf
                        <label class="block">Link an invoice <span class="text-ink-500">(such as a deposit)</span>
                            <input name="number" required placeholder="Invoice number" class="mt-1 block w-40 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                        </label>
                        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Link</button>
                    </form>
                @endcan
            </section>
        </div>

        <div class="space-y-6">
            <form method="POST" action="{{ route('admin.projects.time.store', $project) }}" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                @csrf
                <h2 class="font-semibold">Log time</h2>
                @include('projects::admin._time_fields', ['entry' => $newEntry, 'tasks' => $project->tasks])
                <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Log time</button>
            </form>

            @unless ($timer?->project_id === $project->id)
                <form method="POST" action="{{ route('admin.projects.time.timer.start', $project) }}" class="space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    @csrf
                    <h2 class="font-semibold">Timer</h2>
                    <p class="text-ink-500">Start a timer and stop it when you're done; the time is logged here.@if ($timer) Your timer on {{ $timer->project->title }} is stopped and logged first.@endif</p>
                    @if ($project->tasks->isNotEmpty())
                        <label class="block">Task <span class="text-ink-500">(optional)</span>
                            <select name="task_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                                <option value="">None</option>
                                @foreach ($project->tasks->reject->isDone() as $task)
                                    <option value="{{ $task->id }}">{{ $task->title }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="block">What you're working on <span class="text-ink-500">(optional)</span>
                        <input name="description" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    </label>
                    <button class="w-full rounded-full px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-100">Start timer</button>
                </form>
            @endunless
        </div>
    </div>
</x-admin-layout>
