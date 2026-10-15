@php use App\Addons\ProjectManagement\Duration; use App\Addons\ProjectManagement\Models\Task; @endphp
<x-portal-layout :title="$project->title">
    <p class="mb-2 text-sm"><a href="{{ route('portal.projects.index') }}" class="text-brand-600">{{ __('Projects') }}</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $project->title }}</h1>
        <x-status-badge :status="$project->badge()">{{ __($project->statusLabel()) }}</x-status-badge>
    </div>
    <div class="grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm sm:grid-cols-3">
        <div><div class="text-ink-500">{{ __('Due') }}</div>{{ $project->due_on?->translatedFormat('M j, Y') ?? __('No date set') }}</div>
        <div><div class="text-ink-500">{{ __('Progress') }}</div>{{ __(':done of :total tasks done', ['done' => $project->tasks->where('status', Task::DONE)->count(), 'total' => $project->tasks->count()]) }}</div>
        @if ($showTime)<div><div class="text-ink-500">{{ __('Time spent') }}</div>{{ Duration::format($entries->sum('minutes')) }}</div>@endif
        @if ($project->description)<div class="sm:col-span-3"><div class="text-ink-500">{{ __('About') }}</div>{!! nl2br(e($project->description)) !!}</div>@endif
    </div>

    <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Tasks') }}</h2>
    @if ($project->tasks->isEmpty())
        <p class="text-sm text-ink-500">{{ __('No tasks yet.') }}</p>
    @else
        <ul class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            @foreach ($project->tasks as $task)
                <li class="flex flex-wrap items-center gap-3 px-4 py-2">
                    <span class="{{ $task->isDone() ? 'text-ink-500 line-through' : '' }}">{{ $task->title }}</span>
                    @if ($task->due_on && ! $task->isDone())<span class="text-ink-500">{{ __('due :date', ['date' => $task->due_on->translatedFormat('M j, Y')]) }}</span>@endif
                    <x-status-badge :status="$task->isDone() ? 'done' : ($task->status === Task::TODO ? 'open' : 'on_hold')" class="ml-auto whitespace-nowrap">{{ __($task->statusLabel()) }}</x-status-badge>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($showTime)
        <h2 class="mt-8 mb-3 text-lg font-semibold">{{ __('Time spent') }}</h2>
        @if ($entries->isEmpty())
            <p class="text-sm text-ink-500">{{ __('No time logged yet.') }}</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">{{ __('Date') }}</th><th class="px-4 py-2">{{ __('Work') }}</th><th class="px-4 py-2 text-right">{{ __('Time') }}</th></tr></thead>
                    <tbody class="divide-y divide-ink-100">
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-2">{{ $entry->worked_on->translatedFormat('M j, Y') }}</td>
                                <td class="px-4 py-2">{{ $entry->description ?: ($entry->task?->title ?? __('Work')) }}</td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">{{ Duration::format($entry->minutes) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-portal-layout>
