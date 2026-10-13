<x-admin-layout title="Edit server group">
    <p class="mb-2 text-sm"><a href="{{ route('admin.servers.index') }}" class="text-brand-600">Servers</a></p>
    <h1 class="mb-6 text-2xl font-semibold">Edit {{ $group->name }}</h1>
    <form method="POST" action="{{ route('admin.server-groups.update', $group) }}" class="max-w-md space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf @method('PUT')
        @include('admin.server-groups.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
