<x-admin-layout title="Domain settings">
    <p class="mb-2 text-sm"><a href="{{ route('admin.settings.edit') }}" class="text-brand-600">Settings</a> <span class="text-ink-400">/</span> <a href="{{ route('admin.tlds.index') }}" class="text-brand-600">TLDs and pricing</a></p>
    <h1 class="mb-6 text-2xl font-semibold">Registrars and domain settings</h1>

    <form method="POST" action="{{ route('admin.domain-settings.update') }}" class="max-w-3xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf @method('PUT')
        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">New domains</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                @for ($i = 0; $i < 4; $i++)
                    <label class="block">Default nameserver {{ $i + 1 }}
                        <input name="nameservers[]" value="{{ old('nameservers.'.$i, $nameservers[$i] ?? '') }}" placeholder="ns{{ $i + 1 }}.example.com" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    </label>
                @endfor
            </div>
            <p class="text-ink-600">Domains are registered with these unless the client gives their own. Registrars need at least two.</p>
            <input type="hidden" name="auto_renew_default" value="0">
            <label class="flex items-center gap-2"><input type="checkbox" name="auto_renew_default" value="1" @checked(old('auto_renew_default', $autoRenew))> New domains renew automatically until the client turns it off</label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Renewals and reminders</legend>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">Renewal invoice, days before expiry
                    <input type="number" name="invoice_days_before_expiry" min="0" max="365" required value="{{ old('invoice_days_before_expiry', $invoiceDays) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <span class="text-ink-500">For auto-renewing domains. Leave time to pay before the registrar's deadline.</span>
                </label>
                <label class="block">Reminder emails, days before expiry
                    <input name="reminder_days" value="{{ old('reminder_days', $reminderDays) }}" placeholder="30, 7, 1" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <span class="text-ink-500">One email at each. Domains whose renewal is paid get none. An expiry notice goes out when one lapses.</span>
                </label>
            </div>
            <p class="text-ink-600">The domain run is at 00:35 each night and the registrar sync at 04:10; both show on the <a href="{{ route('admin.cron.show') }}" class="text-brand-600">cron status</a> page.</p>
        </fieldset>

        @foreach ($registrars as $key => $registrar)
            <fieldset class="space-y-3 border-t border-ink-100 pt-5">
                <legend class="mb-1 text-base font-semibold">{{ $registrar->label() }}</legend>
                @foreach ($registrar->configFields() as $name => $field)
                    @php $value = $credentials[$key][$name] ?? ''; @endphp
                    @if (($field['type'] ?? 'text') === 'checkbox')
                        <input type="hidden" name="credentials[{{ $key }}][{{ $name }}]" value="">
                        <label class="flex items-center gap-2"><input type="checkbox" name="credentials[{{ $key }}][{{ $name }}]" value="1" @checked(filled($value))> {{ $field['label'] }}</label>
                    @else
                        <label class="block">{{ $field['label'] }}
                            <input @if ($field['secret'] ?? false) type="password" autocomplete="new-password" placeholder="{{ filled($value) ? 'Saved. Leave blank to keep it.' : '' }}" @else value="{{ $value }}" @endif name="credentials[{{ $key }}][{{ $name }}]" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                            @if ($field['help'] ?? null)<span class="text-ink-500">{{ $field['help'] }}</span>@endif
                        </label>
                    @endif
                @endforeach
            </fieldset>
        @endforeach

        <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>

    <h2 class="mt-8 mb-3 text-lg font-semibold">Test a registrar</h2>
    <div class="flex max-w-3xl flex-wrap gap-2 text-sm">
        @foreach ($registrars as $key => $registrar)
            <form method="POST" action="{{ route('admin.domain-settings.test', $key) }}">@csrf
                <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Test {{ $registrar->label() }}</button>
            </form>
        @endforeach
    </div>
    <p class="mt-2 max-w-3xl text-sm text-ink-500">Saves nothing; makes one read-only call with the saved credentials. The modules were built from each registrar's API documentation; try them in the registrar's test environment first.</p>
</x-admin-layout>
