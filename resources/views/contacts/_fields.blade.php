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
