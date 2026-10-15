<x-admin-layout title="To-do list">
    <h1 class="mb-6 text-2xl font-semibold">To-do list</h1>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                @foreach (['mine' => 'Mine', 'all' => 'Everyone\'s', 'unassigned' => 'For anyone'] as $value => $label)
                    <a href="{{ route('admin.todos.index', ['who' => $value, 'show' => $show]) }}" class="rounded-full px-3 py-1 {{ $who === $value ? 'bg-brand-100 font-semibold text-brand-700' : 'ring-1 ring-ink-300 hover:bg-ink-100' }}">{{ $label }}</a>
                @endforeach
                <span class="mx-1 h-5 w-px bg-ink-200" aria-hidden="true"></span>
                @foreach (['unfinished' => 'Not done', 'overdue' => 'Overdue', 'done' => 'Done', 'all' => 'All'] as $value => $label)
                    <a href="{{ route('admin.todos.index', ['who' => $who, 'show' => $value]) }}" class="rounded-full px-3 py-1 {{ $show === $value ? 'bg-brand-100 font-semibold text-brand-700' : 'ring-1 ring-ink-300 hover:bg-ink-100' }}">{{ $label }}</a>
                @endforeach
            </div>
            @if ($todos->isEmpty())
                <p class="rounded-lg border border-dashed border-ink-300 bg-white p-6 text-center text-sm text-ink-500">Nothing here. {{ $show === 'unfinished' ? 'All caught up.' : '' }}</p>
            @else
                @include('admin.todos._table')
                <div class="mt-4">{{ $todos->links() }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.todos.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a to-do</h2>
            @if ($clientName)<p class="text-ink-600">About {{ $clientName }}.</p><input type="hidden" name="from" value="client">@endif
            @include('admin.todos._fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add</button>
        </form>
    </div>
</x-admin-layout>
