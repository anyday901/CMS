<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="px-4 py-2">Task</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Steps</th><th class="px-4 py-2">{{ $done ?? false ? 'Done' : 'Opened' }}</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($tasks as $task)
                <tr>
                    <td class="px-4 py-2"><a href="{{ route('admin.tasks.show', $task) }}" class="text-brand-600">#{{ $task->id }} {{ $task->title() }}</a></td>
                    <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $task->service->client) }}" class="text-brand-600">{{ $task->service->client->fullName() }}</a></td>
                    <td class="px-4 py-2">{{ count($task->checklist) - $task->remaining() }} of {{ count($task->checklist) }}</td>
                    <td class="whitespace-nowrap px-4 py-2">
                        @if ($task->completed_at){{ $task->completed_at->format('M j, Y') }}@if ($task->completedBy) by {{ $task->completedBy->name }}@endif
                        @else{{ $task->created_at->format('M j, Y') }} <span class="text-ink-500">({{ $task->created_at->diffForHumans() }})</span>@endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
