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
        <label class="block">Tax ID / VAT number
            <input name="tax_id" value="{{ old('tax_id', $client->tax_id) }}" maxlength="100" placeholder="DE123456789" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            <span class="mt-1 block text-ink-500">Printed on their invoices and quotes.@if (App\Billing\VatNumbers::checking()) EU VAT numbers are checked with VIES on save.@endif
                @if ($client->tax_id && App\Billing\VatNumbers::prefix($client->tax_id))
                    {{ $client->tax_id_verified_at ? 'VIES confirmed it on '.$client->tax_id_verified_at->format('M j, Y').'.' : 'Not verified with VIES yet: save again to check it.' }}
                @endif
            </span>
        </label>
        <label class="block">Status
            <select name="status" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @foreach (App\Enums\ClientStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(old('status', $client->status?->value ?? 'active') === $status->value)>{{ ucfirst($status->value) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">Client group
            <select name="client_group_id" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <option value="">None</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected((string) old('client_group_id', $client->client_group_id) === (string) $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
            @can('manage-settings')<span class="mt-1 block text-ink-500">Add groups under <a href="{{ route('admin.client-groups.index') }}" class="text-brand-600">Client groups</a>.</span>@endcan
        </label>
        @include('admin.clients._credit-limit-input')
        @php
            $currencyCodes = array_values(array_unique(array_filter([...App\Models\Currency::codes(), $client->currency])));
            $currencyLocked = $client->currencyLocked();
        @endphp
        <label class="block">Currency
            <select name="currency" @disabled($currencyLocked) class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300 disabled:bg-ink-50 disabled:text-ink-500">
                @foreach ($currencyCodes as $code)
                    <option value="{{ $code }}" @selected(old('currency', $client->currency ?? config('billing.currency')) === $code)>{{ $code }}</option>
                @endforeach
            </select>
            <span class="mt-1 block text-ink-500">
                @if ($currencyLocked)
                    Can't be changed: the client has invoices, payments or credit in {{ $client->currency }}.
                @else
                    Prices, invoices and credit are in this currency. It can't be changed once the client has an invoice.
                @endif
                @can('manage-settings') Add currencies under <a href="{{ route('admin.currencies.index') }}" class="text-brand-600">Currencies</a>.@endcan
            </span>
        </label>
        @if (count(App\Support\Language::all()) > 1)
            <label class="block">Language
                <select name="language" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">{{ App\Support\Language::name(App\Support\Language::main()) }} (the main language)</option>
                    @foreach (App\Support\Language::others() as $code)
                        <option value="{{ $code }}" @selected(old('language', $client->language) === $code)>{{ App\Support\Language::name($code) }}</option>
                    @endforeach
                </select>
                <span class="mt-1 block text-ink-500">For the portal and their emails. Clients can change it in the portal.</span>
            </label>
        @endif
        @include('custom-fields._inputs', ['fields' => $fields, 'values' => $client->custom_fields ?? [], 'wide' => 'md:col-span-2'])
        <div class="space-y-2 md:col-span-2">
            <input type="hidden" name="tax_exempt" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="tax_exempt" value="1" @checked(old('tax_exempt', $client->tax_exempt))> Tax exempt</label>
            <input type="hidden" name="marketing_emails" value="0">
            <label class="flex items-start gap-2"><input type="checkbox" name="marketing_emails" value="1" class="mt-1" @checked(old('marketing_emails', $client->marketing_emails))>
                <span>Agreed to marketing emails (news and offers)
                    @if ($client->marketing_consent_at)<span class="block text-ink-500">{{ $client->marketing_emails ? 'Agreed' : 'Said no' }} on {{ $client->marketing_consent_at->format('M j, Y') }}. Only tick this if the client asked you to.</span>@else<span class="block text-ink-500">Only tick this if the client asked you to.</span>@endif
                </span>
            </label>
            <input type="hidden" name="sms_notifications" value="0">
            <label class="flex items-start gap-2"><input type="checkbox" name="sms_notifications" value="1" class="mt-1" @checked(old('sms_notifications', $client->sms_notifications))>
                <span>Agreed to account texts (invoices, payments, services and ticket replies) at the phone number above
                    <span class="block text-ink-500">Which texts are sent is set under Settings › SMS.</span>
                </span>
            </label>
        </div>
        <label class="block md:col-span-2">Profile notes <span class="text-ink-500">(dated notes, and pinned ones, are on the client's page)</span>
            <textarea name="notes" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('notes', $client->notes) }}</textarea>
        </label>
        <div class="md:col-span-2">
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
        </div>
    </form>
</x-admin-layout>
