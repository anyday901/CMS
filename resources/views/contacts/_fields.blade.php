<div class="grid gap-3 sm:grid-cols-2">
    <label class="block">{{ __('First name') }}
        <input name="first_name" value="{{ old('first_name', $contact->first_name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">{{ __('Last name') }}
        <input name="last_name" value="{{ old('last_name', $contact->last_name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">{{ __('Email') }}
        <input type="email" name="email" value="{{ old('email', $contact->email) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">{{ __('Phone') }}
        <input name="phone" value="{{ old('phone', $contact->phone) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<label class="flex items-center gap-2"><input type="checkbox" name="invoice_emails" value="1" @checked(old('invoice_emails', $contact->invoice_emails))> {{ __('Copy on invoice, reminder and receipt emails') }}</label>
<label class="flex items-center gap-2"><input type="checkbox" name="support_emails" value="1" @checked(old('support_emails', $contact->support_emails))> {{ __('Copy on ticket emails, and can open and reply to tickets by email') }}</label>
<div class="space-y-2 rounded-md bg-ink-50 p-4">
    <input type="hidden" name="portal_access" value="0">
    <label class="flex items-start gap-2"><input type="checkbox" name="portal_access" value="1" class="mt-1" @checked(old('portal_access', $contact->portal_access))>
        <span><span class="font-medium">{{ __('Can sign in to the portal') }}</span> {{ __('with this email and their own password.') }}
            @unless ($contact->password){{ __('They get an email with a link to choose one, or, if they already sign in to another account with this email, they use that login here too.') }}@endunless</span>
    </label>
    <p class="pl-6 text-ink-600">{{ __('What they can do there:') }}</p>
    @foreach (App\Models\ClientContact::PERMISSIONS as $key => $label)
        <label class="flex items-start gap-2 pl-6"><input type="checkbox" name="permissions[]" value="{{ $key }}" class="mt-1" @checked(in_array($key, old('permissions', $contact->permissions ?? []), true))> {{ __($label) }}</label>
    @endforeach
    <p class="pl-6 text-ink-500">{{ __("They can never change the account's details or contacts, and they can turn on two-factor authentication for their own login.") }}</p>
</div>
