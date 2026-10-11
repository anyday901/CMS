<x-admin-layout title="Tickets">
    <h1 class="mb-4 text-2xl font-semibold">Tickets</h1>
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach (['waiting' => 'Waiting on us', 'answered' => 'Answered', 'on_hold' => 'On hold', 'closed' => 'Closed', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.tickets.index', array_filter(['status' => $value === 'waiting' ? null : $value, 'department' => $filters['department'], 'mine' => $filters['mine'] ? 1 : null])) }}"
               class="rounded-full px-3 py-1 {{ $filters['status'] === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
        <input type="hidden" name="status" value="{{ $filters['status'] === 'waiting' ? '' : $filters['status'] }}">
        <select name="department" aria-label="Department" onchange="this.form.submit()" class="ml-auto rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            <option value="">All departments</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}" @selected($filters['department'] === $department->id)>{{ $department->name }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1.5"><input type="checkbox" name="mine" value="1" onchange="this.form.submit()" @checked($filters['mine'])> Assigned to me</label>
    </form>
    @if ($tickets->isEmpty())
        <p class="text-sm text-ink-500">{{ $filters['status'] === 'waiting' ? 'No tickets waiting on a reply.' : 'No tickets here.' }}</p>
    @else
        @include('admin.tickets._table')
        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
</x-admin-layout>
