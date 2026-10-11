<x-admin-layout :title="'Task #'.$task->id">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">#{{ $task->id }} {{ $task->title() }}</h1>
        <x-status-badge :status="$task->isOpen() ? 'open' : 'done'" />
    </div>

    <div class="mb-6 grid gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm md:grid-cols-3">
        <div><div class="text-ink-500">Client</div><a href="{{ route('admin.clients.show', $task->service->client) }}" class="text-brand-600">{{ $task->service->client->fullName() }}</a></div>
        <div><div class="text-ink-500">Service</div><a href="{{ route('admin.services.show', $task->service) }}" class="text-brand-600">{{ $task->service->description() }}</a></div>
        <div><div class="text-ink-500">{{ $task->isOpen() ? 'Opened' : 'Done' }}</div>
            @if ($task->isOpen()){{ $task->created_at->format('M j, Y g:ia') }}
            @else{{ $task->completed_at->format('M j, Y g:ia') }}@if ($task->completedBy) by {{ $task->completedBy->name }}@endif
            @endif
        </div>
    </div>

    @php $editable = $task->isOpen() && auth()->user()->can('manage-clients'); @endphp
    <form method="POST" action="{{ route('admin.tasks.update', $task) }}" class="space-y-4 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        <fieldset>
            <legend class="mb-2 font-semibold">Checklist</legend>
            <ul class="space-y-2">
                @foreach ($task->checklist as $i => $step)
                    <li>
                        <label class="flex items-start gap-2">
                            <input type="checkbox" name="done[]" value="{{ $i }}" class="mt-0.5" @checked(in_array($i, old('done', []), false) || $step['done']) @disabled(! $editable)>
                            <span class="{{ $step['done'] ? 'text-ink-500 line-through' : '' }}">{{ $step['text'] }}</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        </fieldset>
        <label class="block">Notes
            <textarea name="notes" rows="3" class="mt-1 block w-full max-w-2xl rounded-md px-3 py-2 ring-1 ring-ink-300" @disabled(! $editable)>{{ old('notes', $task->notes) }}</textarea>
        </label>
        @if ($editable)
            <div class="flex flex-wrap gap-2">
                <button name="complete" value="1" class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Mark done</button>
                <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Save progress</button>
            </div>
        @endif
    </form>
</x-admin-layout>
