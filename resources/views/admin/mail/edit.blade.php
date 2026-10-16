@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="Mail">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Mail settings</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">How invoices, receipts, ticket replies and every other email are sent. Mail services such as Mailgun, Postmark, Amazon SES, SendGrid and Microsoft 365 all offer an SMTP server to use here. What was sent, and any error, is in the <a href="{{ route('admin.emails.index') }}" class="text-brand-600">email log</a>.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <form method="POST" action="{{ route('admin.mail.update') }}" class="space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2">
            @csrf @method('PUT')

            <fieldset class="space-y-3">
                <legend class="mb-1 text-base font-semibold">Sending</legend>
                <label class="block">Send email with
                    <select name="mailer" class="{{ $input }}" onchange="document.getElementById('smtp-fields').hidden = this.value !== 'smtp'">
                        @foreach ($mailers as $key => $label)
                            <option value="{{ $key }}" @selected(old('mailer', $mailer) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div id="smtp-fields" class="space-y-3" @if (old('mailer', $mailer) !== 'smtp') hidden @endif>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block sm:col-span-2">SMTP server
                            <input name="host" value="{{ old('host', $host) }}" maxlength="255" placeholder="smtp.example.com" class="{{ $input }}" autocomplete="off">
                        </label>
                        <label class="block">Port
                            <input type="number" name="port" min="1" max="65535" value="{{ old('port', $port) }}" placeholder="587" class="{{ $input }}">
                        </label>
                    </div>
                    <label class="block">Security
                        <select name="scheme" class="{{ $input }}">
                            @foreach ($security as $key => $label)
                                <option value="{{ $key }}" @selected(old('scheme', $scheme) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">Username
                            <input name="username" value="{{ old('username', $username) }}" maxlength="255" class="{{ $input }}" autocomplete="off">
                        </label>
                        <label class="block">Password
                            <input type="password" name="password" maxlength="500" autocomplete="new-password" placeholder="{{ $hasPassword ? 'Saved; leave blank to keep it' : '' }}" class="{{ $input }}">
                        </label>
                    </div>
                    @if ($hasPassword)
                        <label class="flex items-center gap-2"><input type="hidden" name="forget_password" value="0"><input type="checkbox" name="forget_password" value="1"> Remove the saved password</label>
                    @endif
                    <p class="text-ink-500">The password is kept encrypted.</p>
                </div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="mb-1 text-base font-semibold">From</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">Name
                        <input name="from_name" value="{{ old('from_name', $fromName) }}" required maxlength="255" class="{{ $input }}">
                    </label>
                    <label class="block">Email address
                        <input type="email" name="from_address" value="{{ old('from_address', $fromAddress) }}" required maxlength="255" class="{{ $input }}">
                    </label>
                </div>
                <p class="text-ink-500">Use an address on a domain your mail server is allowed to send for (with SPF and DKIM set up), or emails may land in spam. Ticket emails are sent with their department's address to reply to.</p>
            </fieldset>

            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save mail settings</button>
        </form>

        <form method="POST" action="{{ route('admin.mail.test') }}" class="space-y-3 self-start rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            <h2 class="text-base font-semibold">Send a test email</h2>
            <p class="text-ink-600">Sent straight away with the saved settings, so any error shows here.</p>
            <label class="block">To
                <input type="email" name="to" value="{{ old('to', auth()->user()->email) }}" required maxlength="255" class="{{ $input }}">
            </label>
            <button class="rounded-full bg-white px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Send test</button>
        </form>
    </div>
</x-admin-layout>
