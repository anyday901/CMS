<x-admin-layout title="Tasks">
    <h1 class="mb-4 text-2xl font-semibold">Tasks</h1>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['' => 'Open', 'done' => 'Done'] as $value => $label)
            <a href="{{ route('admin.tasks.index', array_filter(['status' => $value])) }}"
               class="rounded-full px-3 py-1 {{ ($done ? 'done' : '') === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
    </div>
    @if ($tasks->isEmpty())
        <p class="text-sm text-ink-500">{{ $done ? 'No finished tasks yet.' : 'Nothing to do. Products on the manual fulfillment module add tasks here.' }}</p>
    @else
        @include('admin.tasks._table')
        <div class="mt-4">{{ $tasks->links() }}</div>
    @endif
</x-admin-layout>
