<x-portal-layout :title="$contact->exists ? 'Edit contact' : 'Add contact'">
    <h1 class="mb-2 text-2xl font-semibold">{{ $contact->exists ? 'Edit contact' : 'Add a contact' }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Contacts are other people on your account, such as a bookkeeper. They can get copies of your emails, and you can give them their own login to the parts of the portal they need.</p>
    <form method="POST" action="{{ $contact->exists ? route('portal.contacts.update', $contact) : route('portal.contacts.store') }}" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @if ($contact->exists) @method('PUT') @endif
        @include('contacts._fields')
        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ $contact->exists ? 'Save' : 'Add contact' }}</button>
        <a href="{{ route('portal.account.edit') }}" class="ml-2 text-brand-600">Cancel</a>
    </form>
</x-portal-layout>
