<x-admin-layout title="Edit product group">
    <h1 class="mb-6 text-2xl font-semibold">Edit {{ $group->name }}</h1>
    <form method="POST" action="{{ route('admin.product-groups.update', $group) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.product-groups.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
