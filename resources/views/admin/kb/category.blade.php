<x-admin-layout title="Edit category">
    @include('admin.support._nav')
    <h1 class="mb-6 text-2xl font-semibold">Edit category</h1>
    <form method="POST" action="{{ route('admin.kb-categories.update', $category) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.kb._category-fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
    <form method="POST" action="{{ route('admin.kb-categories.destroy', $category) }}" class="mt-4" onsubmit="return confirm('Delete this category?')">
        @csrf @method('DELETE')
        <button class="rounded-full px-4 py-2 text-sm text-red-700 ring-1 ring-red-200 hover:bg-red-50">Delete category</button>
    </form>
</x-admin-layout>
