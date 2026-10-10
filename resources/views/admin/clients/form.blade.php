<x-admin-layout :title="$client->exists ? 'Edit client' : 'Add client'">
    <h1 class="mb-6 text-2xl font-semibold">{{ $client->exists ? 'Edit '.$client->fullName() : 'Add client' }}</h1>
    <form method="POST" action="{{ $client->exists ? route('admin.clients.update', $client) : route('admin.clients.store') }}"
          class="grid max-w-3xl gap-4 rounded-lg border border-ink-200 bg-white p-6 text-sm md:grid-cols-2">
        @csrf
        @if ($client->exists) @method('PUT') @endif
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
        <label class="block">Status
            <select name="status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @foreach (App\Enums\ClientStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $client->status?->value ?? 'active') === $status->value)>{{ ucfirst($status->value) }}</option>
                @endforeach
            </select>
        </label>
        <div class="md:col-span-2">
            <input type="hidden" name="tax_exempt" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="tax_exempt" value="1" @checked(old('tax_exempt', $client->tax_exempt))> Tax exempt</label>
        </div>
        <label class="block md:col-span-2">Notes
            <textarea name="notes" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('notes', $client->notes) }}</textarea>
        </label>
        <div class="md:col-span-2">
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
        </div>
    </form>
</x-admin-layout>
