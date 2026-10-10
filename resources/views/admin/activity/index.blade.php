<x-admin-layout title="Activity log">
    <h1 class="mb-4 text-2xl font-semibold">Activity log</h1>
    <form method="GET" class="mb-4 flex flex-wrap gap-2 text-sm">
        <label for="activity-search" class="sr-only">Search</label>
        <input id="activity-search" name="q" value="{{ $search }}" placeholder="Search description, name or IP" class="w-72 rounded-md px-3 py-1.5 ring-1 ring-ink-300">
        <label for="activity-actor" class="sr-only">Who</label>
        <select id="activity-actor" name="actor" class="rounded-md px-3 py-1.5 ring-1 ring-ink-300">
            <option value="">Everyone</option>
            @foreach (['staff' => 'Staff', 'client' => 'Clients', 'system' => 'System'] as $value => $label)
                <option value="{{ $value }}" @selected($actor === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Filter</button>
    </form>
    @if ($entries->isEmpty())
        <p class="text-sm text-ink-500">Nothing logged yet.</p>
    @else
        @include('admin.activity._list')
        <div class="mt-4">{{ $entries->links() }}</div>
    @endif
</x-admin-layout>
