<x-admin-layout title="Tickets">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">Tickets</h1>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.canned-replies.index') }}" class="rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Canned replies</a>
            @can('manage-settings')<a href="{{ route('admin.escalation-rules.index') }}" class="rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Escalation rules</a>@endcan
        </div>
    </div>
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach (['waiting' => 'Waiting on us', 'overdue' => 'Overdue'.($overdueCount ? " ({$overdueCount})" : ''), 'answered' => 'Answered', 'on_hold' => 'On hold', 'closed' => 'Closed', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.tickets.index', array_filter(['status' => $value === 'waiting' ? null : $value, 'department' => $filters['department'], 'mine' => $filters['mine'] ? 1 : null])) }}"
               class="rounded-full px-3 py-1 {{ $filters['status'] === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
        <input type="hidden" name="status" value="{{ $filters['status'] === 'waiting' ? '' : $filters['status'] }}">
        <label class="ml-auto flex items-center gap-1.5">Department
            <select name="department" onchange="this.form.submit()" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
                <option value="">All departments</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected($filters['department'] === $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-center gap-1.5"><input type="checkbox" name="mine" value="1" onchange="this.form.submit()" @checked($filters['mine'])> Assigned to me</label>
    </form>
    @if ($tickets->isEmpty())
        <p class="text-sm text-ink-500">{{ $filters['status'] === 'waiting' ? 'No tickets waiting on a reply.' : 'No tickets here.' }}</p>
    @else
        @include('admin.tickets._table')
        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
</x-admin-layout>
