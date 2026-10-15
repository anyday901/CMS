<x-admin-layout :title="$announcement->exists ? 'Edit announcement' : 'New announcement'">
    @include('admin.support._nav')
    <h1 class="mb-6 text-2xl font-semibold">{{ $announcement->exists ? 'Edit announcement' : 'New announcement' }}</h1>
    <form method="POST" action="{{ $announcement->exists ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}" class="max-w-3xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf
        @if ($announcement->exists) @method('PUT') @endif
        <label class="block">Title
            <input name="title" value="{{ old('locale') ? $announcement->title : old('title', $announcement->title) }}" required maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">Date
            <input type="datetime-local" name="published_at" value="{{ old('published_at', $announcement->published_at?->format('Y-m-d\TH:i')) }}" required class="mt-1 rounded-md px-3 py-2 ring-1 ring-ink-300">
            <span class="text-ink-500">Shown to clients from then.</span>
        </label>
        <label class="block">Announcement (Markdown)
            <textarea name="body" rows="12" required class="mt-1 w-full rounded-md px-3 py-2 font-mono text-xs ring-1 ring-ink-300">{{ old('locale') ? $announcement->body : old('body', $announcement->body) }}</textarea>
        </label>
        <label class="flex items-center gap-2"><input type="checkbox" name="published" value="1" @checked(old('published', $announcement->published))> Published</label>
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $announcement->exists ? 'Save' : 'Add announcement' }}</button>
    </form>
    @if ($announcement->exists)
        <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" class="mt-4" onsubmit="return confirm('Delete this announcement?')">
            @csrf @method('DELETE')
            <button class="rounded-full px-4 py-2 text-sm text-red-700 ring-1 ring-red-200 hover:bg-red-50">Delete announcement</button>
        </form>
        @include('admin.support._translations', ['model' => $announcement, 'type' => 'announcements'])
    @endif
</x-admin-layout>
