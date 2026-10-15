<x-admin-layout title="Edit task">
    <p class="mb-2 text-sm"><a href="{{ route('admin.projects.show', $task->project) }}#tasks" class="text-brand-600">{{ $task->project->title }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ $task->title }}</h1>
    <p class="mb-6 text-sm text-ink-500">Added {{ $task->created_at->format('M j, Y') }}@if ($task->completed_at) · done {{ $task->completed_at->format('M j, Y') }}@endif</p>
    <form method="POST" action="{{ route('admin.projects.tasks.update', $task) }}" class="max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        @include('projects::admin._task_fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
    <form method="POST" action="{{ route('admin.projects.tasks.destroy', $task) }}" class="mt-4" onsubmit="return confirm('Delete this task? Time logged on it stays on the project.')">
        @csrf @method('DELETE')
        <button class="text-sm text-red-600 hover:underline">Delete this task</button>
    </form>
</x-admin-layout>
