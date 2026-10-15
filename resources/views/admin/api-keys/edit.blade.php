<x-admin-layout title="Edit API key">
    <p class="mb-2 text-sm"><a href="{{ route('admin.api-keys.index') }}" class="text-brand-600">API keys</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Edit {{ $key->name }}</h1>
    <p class="mb-6 max-w-xl text-sm text-ink-600">
        <span class="font-mono">{{ $key->prefix }}_…</span> · {{ $key->status() }}
        · made {{ $key->created_at->format('M j, Y') }}@if ($key->user) by {{ $key->user->name }}@endif
        · expires {{ $key->expires_at?->format('M j, Y') ?? 'never' }}.
        Staff replies sent with this key are in the name of the staff member who made it. To change the key itself or its expiry, make a new key and revoke this one.
    </p>

    <form method="POST" action="{{ route('admin.api-keys.update', $key) }}" class="max-w-xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        @include('admin.api-keys.fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
