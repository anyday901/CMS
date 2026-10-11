<x-admin-layout :title="$contact->exists ? 'Edit contact' : 'Add contact'">
    <h1 class="mb-6 text-2xl font-semibold">{{ $contact->exists ? 'Edit contact' : 'Add a contact' }} for <a href="{{ route('admin.clients.show', $client) }}" class="text-brand-600">{{ $client->fullName() }}</a></h1>
    <form method="POST" action="{{ $contact->exists ? route('admin.contacts.update', $contact) : route('admin.contacts.store', $client) }}" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @if ($contact->exists) @method('PUT') @endif
        @include('contacts._fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $contact->exists ? 'Save' : 'Add contact' }}</button>
    </form>
</x-admin-layout>
