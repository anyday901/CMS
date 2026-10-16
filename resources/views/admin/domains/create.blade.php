<x-admin-layout title="Add domain">
    <p class="mb-2 text-sm"><a href="{{ route('admin.clients.show', $client) }}" class="text-brand-600">{{ $client->fullName() }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Add a domain</h1>
    <p class="mb-6 max-w-3xl text-sm text-ink-600">For a domain the client already has at one of your registrars, such as one moved over from another billing system. Nothing is invoiced or sent to the registrar; to sell a new domain, order it from the store as the client.</p>
    <form method="POST" action="{{ route('admin.domains.store', $client) }}" class="max-w-3xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        <label class="block">Domain
            <input name="name" value="{{ old('name') }}" required maxlength="253" placeholder="example.com" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        @include('admin.domains._fields', ['domain' => new App\Models\Domain(['auto_renew' => true, 'status' => 'active'])])
        <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">Add domain</button>
    </form>
</x-admin-layout>
