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
</x-portal-layout>
