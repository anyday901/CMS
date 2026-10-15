<x-admin-layout title="Edit client group">
    <p class="mb-2 text-sm"><a href="{{ route('admin.client-groups.index') }}" class="text-brand-600">Client groups</a></p>
    <h1 class="mb-6 text-2xl font-semibold">Edit {{ $group->name }}</h1>
    <form method="POST" action="{{ route('admin.client-groups.update', $group) }}" class="max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        @include('admin.client-groups.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
