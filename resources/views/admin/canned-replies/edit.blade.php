<x-admin-layout title="Edit canned reply">
    <h1 class="mb-6 text-2xl font-semibold">Edit canned reply</h1>
    <form method="POST" action="{{ route('admin.canned-replies.update', $reply) }}" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.canned-replies.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
