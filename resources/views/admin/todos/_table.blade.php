{{-- $todos, $hideClient (bool), $from (optional: 'client') --}}
<div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
    <table class="min-w-full text-sm">
        <thead class="bg-ink-50 text-left text-ink-500">
            <tr><th class="w-8 px-4 py-2"><span class="sr-only">Done</span></th><th class="px-4 py-2">To do</th>@unless ($hideClient ?? false)<th class="px-4 py-2">Client</th>@endunless<th class="px-4 py-2">For</th><th class="px-4 py-2">Due</th><th class="px-4 py-2">Status</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @foreach ($todos as $todo)
                <tr class="{{ $todo->isDone() ? 'text-ink-500' : '' }}">
                    <td class="px-4 py-2 align-top">
                        <form method="POST" action="{{ route('admin.todos.status', $todo) }}">
                            @csrf @method('PUT')
                            <input type="hidden" name="status" value="{{ $todo->isDone() ? App\Models\Todo::OPEN : App\Models\Todo::DONE }}">
                            <button title="{{ $todo->isDone() ? 'Mark not done' : 'Mark done' }}" class="flex size-5 items-center justify-center rounded ring-1 {{ $todo->isDone() ? 'bg-brand-600 text-white ring-brand-600' : 'ring-ink-400 hover:bg-brand-50' }}">
                                @if ($todo->isDone())✓@endif<span class="sr-only">{{ $todo->isDone() ? 'Mark not done' : 'Mark done' }}: {{ $todo->title }}</span>
                            </button>
                        </form>
                    </td>
                    <td class="px-4 py-2">
                        <a href="{{ route('admin.todos.edit', $todo) }}" class="{{ $todo->isDone() ? 'line-through' : 'text-brand-600' }}">{{ $todo->title }}</a>
                        @if ($todo->description)<div class="text-ink-500">{{ Str::limit($todo->description, 120) }}</div>@endif
                    </td>
                    @unless ($hideClient ?? false)
                        <td class="px-4 py-2">@if ($todo->client)<a href="{{ route('admin.clients.show', $todo->client) }}" class="text-brand-600">{{ $todo->client->fullName() }}</a>@else<span class="text-ink-400">—</span>@endif</td>
                    @endunless
                    <td class="px-4 py-2">{{ $todo->user?->name ?? 'Anyone' }}</td>
                    <td class="whitespace-nowrap px-4 py-2 {{ $todo->isOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $todo->due_on?->format('M j, Y') ?? '—' }}@if ($todo->isOverdue()) · overdue @endif</td>
                    <td class="px-4 py-2"><x-status-badge :status="$todo->isDone() ? 'done' : ($todo->status === App\Models\Todo::OPEN ? 'open' : 'on_hold')" class="whitespace-nowrap">{{ $todo->statusLabel() }}</x-status-badge></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
