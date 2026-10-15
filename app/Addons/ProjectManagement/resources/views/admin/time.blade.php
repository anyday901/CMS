<x-admin-layout title="Edit time">
    <p class="mb-2 text-sm"><a href="{{ route('admin.projects.show', $entry->project) }}#time" class="text-brand-600">{{ $entry->project->title }}</a></p>
    <h1 class="mb-6 text-2xl font-semibold">Edit time</h1>
    <form method="POST" action="{{ route('admin.projects.time.update', $entry) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        @include('projects::admin._time_fields', ['tasks' => $entry->project->tasks])
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
    <form method="POST" action="{{ route('admin.projects.time.destroy', $entry) }}" class="mt-4" onsubmit="return confirm('Delete this time?')">
        @csrf @method('DELETE')
        <button class="text-sm text-red-600 hover:underline">Delete this time</button>
    </form>
</x-admin-layout>
