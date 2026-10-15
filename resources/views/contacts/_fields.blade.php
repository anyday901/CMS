<div class="grid gap-3 sm:grid-cols-2">
    <label class="block">First name
        <input name="first_name" value="{{ old('first_name', $contact->first_name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Last name
        <input name="last_name" value="{{ old('last_name', $contact->last_name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Email
        <input type="email" name="email" value="{{ old('email', $contact->email) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
    <label class="block">Phone
        <input name="phone" value="{{ old('phone', $contact->phone) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
    </label>
</div>
<label class="flex items-center gap-2"><input type="checkbox" name="invoice_emails" value="1" @checked(old('invoice_emails', $contact->invoice_emails))> Copy on invoice, reminder and receipt emails</label>
<label class="flex items-center gap-2"><input type="checkbox" name="support_emails" value="1" @checked(old('support_emails', $contact->support_emails))> Copy on ticket emails, and can open and reply to tickets by email</label>
<div class="space-y-2 rounded-md bg-ink-50 p-4">
    <input type="hidden" name="portal_access" value="0">
    <label class="flex items-start gap-2"><input type="checkbox" name="portal_access" value="1" class="mt-1" @checked(old('portal_access', $contact->portal_access))>
        <span><span class="font-medium">Can sign in to the portal</span> with this email and their own password.
            @unless ($contact->password)They get an email with a link to choose one.@endunless</span>
    </label>
    <p class="pl-6 text-ink-600">What they can do there:</p>
    @foreach (App\Models\ClientContact::PERMISSIONS as $key => $label)
        <label class="flex items-start gap-2 pl-6"><input type="checkbox" name="permissions[]" value="{{ $key }}" class="mt-1" @checked(in_array($key, old('permissions', $contact->permissions ?? []), true))> {{ $label }}</label>
    @endforeach
    <p class="pl-6 text-ink-500">They can never change the account's details or contacts, and they can turn on two-factor authentication for their own login.</p>
</div>
