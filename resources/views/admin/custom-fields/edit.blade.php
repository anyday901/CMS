<x-admin-layout title="Edit custom field">
    <h1 class="mb-6 text-2xl font-semibold">Edit custom field</h1>
    <form method="POST" action="{{ route('admin.custom-fields.update', $field) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.custom-fields.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
