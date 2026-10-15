<x-portal-layout title="Account">
    <h1 class="mb-6 text-2xl font-semibold">Your account</h1>
    <div class="grid gap-6 md:grid-cols-3">
        <form method="POST" action="{{ route('portal.account.update') }}" class="grid gap-4 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2 md:grid-cols-2">
            @csrf
            @method('PUT')
            @foreach ([
                'first_name' => 'First name', 'last_name' => 'Last name', 'company' => 'Company', 'email' => 'Email',
                'phone' => 'Phone', 'address1' => 'Address', 'address2' => 'Address line 2', 'city' => 'City',
                'state' => 'State', 'postcode' => 'ZIP / postcode', 'country' => 'Country (2-letter code)',
            ] as $field => $label)
                <label class="block">{{ $label }}
                    <input name="{{ $field }}" value="{{ old($field, $client->$field) }}" @required(in_array($field, ['first_name', 'last_name', 'email']))
                           @if ($field === 'email') type="email" @endif class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            @endforeach
            @include('custom-fields._inputs', ['fields' => $fields, 'values' => $client->custom_fields ?? [], 'wide' => 'md:col-span-2'])
            <div class="md:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save details</button></div>
        </form>

        <form method="POST" action="{{ route('portal.account.password') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            @method('PUT')
            <h2 class="font-semibold">Change password</h2>
            <label class="block">Current password<input type="password" name="current_password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <label class="block">New password<input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <label class="block">Confirm new password<input type="password" name="password_confirmation" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Change password</button>
        </form>
    </div>

    <div class="mt-8 mb-3 flex items-center gap-3">
        <h2 class="text-lg font-semibold">Contacts</h2>
        <a href="{{ route('portal.contacts.create') }}" class="text-sm text-brand-600">Add contact</a>
    </div>
    <p class="mb-3 max-w-2xl text-sm text-ink-600">Other people on your account, such as a bookkeeper. They can get copies of your emails, and you can give each one their own login with just the access they need.</p>
    @include('contacts._list', ['contacts' => $client->contacts, 'editRoute' => 'portal.contacts.edit', 'destroyRoute' => 'portal.contacts.destroy', 'inviteRoute' => 'portal.contacts.invite'])

    <div class="mt-8 flex flex-wrap items-center gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        <p class="grow text-ink-600">Two-factor authentication, recent logins, and a copy of your data are under <a href="{{ route('portal.security.show') }}" class="text-brand-600">Security</a>.</p>
        <a href="{{ route('portal.account.export') }}" class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">Download my data</a>
    </div>
</x-portal-layout>
