<x-admin-layout title="Edit to-do">
    <p class="mb-2 text-sm"><a href="{{ route('admin.todos.index') }}" class="text-brand-600">To-do list</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ $todo->title }}</h1>
    <p class="mb-6 text-sm text-ink-500">Added {{ $todo->created_at->format('M j, Y') }}{{ $todo->creator ? ' by '.$todo->creator->name : '' }}@if ($todo->completed_at) · done {{ $todo->completed_at->format('M j, Y') }}@endif @if ($todo->client) · about <a href="{{ route('admin.clients.show', $todo->client) }}" class="text-brand-600">{{ $todo->client->fullName() }}</a>@endif</p>
    <form method="POST" action="{{ route('admin.todos.update', $todo) }}" class="max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        @include('admin.todos._fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
    <form method="POST" action="{{ route('admin.todos.destroy', $todo) }}" class="mt-4" onsubmit="return confirm('Delete this to-do?')">
        @csrf @method('DELETE')
        <button class="text-sm text-red-600 hover:underline">Delete this to-do</button>
    </form>
</x-admin-layout>
