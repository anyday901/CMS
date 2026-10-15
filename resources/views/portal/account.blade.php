<x-portal-layout :title="__('Account')">
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Your account') }}</h1>
    <div class="grid gap-6 md:grid-cols-3">
        <form method="POST" action="{{ route('portal.account.update') }}" class="grid gap-4 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2 md:grid-cols-2">
            @csrf
            @method('PUT')
            @foreach ([
                'first_name' => __('First name'), 'last_name' => __('Last name'), 'company' => __('Company'), 'email' => __('Email'),
                'phone' => __('Phone'), 'address1' => __('Address'), 'address2' => __('Address line 2'), 'city' => __('City'),
                'state' => __('State'), 'postcode' => __('ZIP / postcode'), 'country' => __('Country (2-letter code)'),
            ] as $field => $label)
                <label class="block">{{ $label }}
                    <input name="{{ $field }}" value="{{ old($field, $client->$field) }}" @required(in_array($field, ['first_name', 'last_name', 'email']))
                           @if ($field === 'email') type="email" @endif class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
            @endforeach
            @include('custom-fields._inputs', ['fields' => $fields, 'values' => $client->custom_fields ?? [], 'wide' => 'md:col-span-2'])
            <div class="md:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Save details') }}</button></div>
        </form>

        <form method="POST" action="{{ route('portal.account.password') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            @method('PUT')
            <h2 class="font-semibold">{{ __('Change password') }}</h2>
            <label class="block">{{ __('Current password') }}<input type="password" name="current_password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <label class="block">{{ __('New password') }}<input type="password" name="password" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <label class="block">{{ __('Confirm new password') }}<input type="password" name="password_confirmation" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Change password') }}</button>
        </form>
    </div>

    <form method="POST" action="{{ route('portal.account.marketing') }}" class="mt-6 flex flex-wrap items-center gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        @csrf
        @method('PUT')
        <h2 class="w-full font-semibold">{{ $offersTexts ? __('Email and text preferences') : __('Email preferences') }}</h2>
        <div class="grow space-y-2">
            <input type="hidden" name="marketing_emails" value="0">
            <label class="flex items-start gap-2"><input type="checkbox" name="marketing_emails" value="1" class="mt-1" @checked($client->marketing_emails)>
                <span>{{ __('Email me news and special offers. Emails about your account, such as invoices and support replies, always come.') }}</span></label>
            @if ($offersTexts)
                <input type="hidden" name="sms_notifications" value="0">
                <label class="flex items-start gap-2"><input type="checkbox" name="sms_notifications" value="1" class="mt-1" @checked($client->sms_notifications)>
                    <span>{{ __('Text me about my account, such as new invoices and support replies, at :phone.', ['phone' => $client->phone ?: __('my phone number (add it above)')]) }}</span></label>
            @endif
        </div>
        <button class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Save preference') }}</button>
    </form>

    <div class="mt-8 mb-3 flex items-center gap-3">
        <h2 class="text-lg font-semibold">{{ __('Contacts') }}</h2>
        <a href="{{ route('portal.contacts.create') }}" class="text-sm text-brand-600">{{ __('Add contact') }}</a>
    </div>
    <p class="mb-3 max-w-2xl text-sm text-ink-600">{{ __('Other people on your account, such as a bookkeeper. They can get copies of your emails, and you can give each one their own login with just the access they need.') }}</p>
    @include('contacts._list', ['contacts' => $client->contacts, 'editRoute' => 'portal.contacts.edit', 'destroyRoute' => 'portal.contacts.destroy', 'inviteRoute' => 'portal.contacts.invite'])

    <div class="mt-8 flex flex-wrap items-center gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
        <p class="grow text-ink-600">{!! __('Two-factor authentication, recent logins, and a copy of your data are under :link.', ['link' => '<a href="'.e(route('portal.security.show')).'" class="text-brand-600">'.e(__('Security')).'</a>']) !!}</p>
        <a href="{{ route('portal.account.export') }}" class="rounded-full px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Download my data') }}</a>
    </div>
</x-portal-layout>
