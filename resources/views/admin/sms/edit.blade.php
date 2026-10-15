@php $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300'; @endphp
<x-admin-layout title="SMS">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">SMS notifications</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Text staff about events such as new orders, and text clients who agree to it (under <strong>Account</strong> in the portal, or on their edit page) about invoices, payments, their services and ticket replies. Other providers can be added; see <code class="rounded bg-ink-100 px-1">config/sms.php</code>.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <form method="POST" action="{{ route('admin.sms.update') }}" class="space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2">
            @csrf @method('PUT')

            <fieldset class="space-y-3">
                <legend class="mb-1 text-base font-semibold">Provider</legend>
                <label class="block">Send with
                    <select name="provider" class="{{ $input }}" onchange="document.querySelectorAll('[data-provider]').forEach(el => el.hidden = el.dataset.provider !== this.value)">
                        <option value="">Off</option>
                        @foreach ($providers as $key => $item)
                            <option value="{{ $key }}" @selected(old('provider', $provider) === $key)>{{ $item->name() }}</option>
                        @endforeach
                    </select>
                </label>
                @foreach ($providers as $key => $item)
                    <div data-provider="{{ $key }}" class="space-y-3" @if (old('provider', $provider) !== $key) hidden @endif>
                        @foreach ($item->fields() as $name => $field)
                            <label class="block">{{ $field['label'] }}
                                @if ($field['secret'] ?? false)
                                    <input type="password" name="credentials[{{ $name }}]" autocomplete="new-password" placeholder="{{ $provider === $key && filled($credentials[$name] ?? null) ? 'Saved; leave blank to keep it' : '' }}" class="{{ $input }}">
                                @else
                                    <input name="credentials[{{ $name }}]" value="{{ $provider === $key ? ($credentials[$name] ?? '') : '' }}" class="{{ $input }}" autocomplete="off">
                                @endif
                                @if (! empty($field['help']))<span class="mt-1 block text-ink-500">{{ $field['help'] }}</span>@endif
                            </label>
                        @endforeach
                    </div>
                @endforeach
                <div class="flex flex-wrap gap-4">
                    <label class="block grow">Sending number or sender ID
                        <input name="from" value="{{ old('from', $from) }}" maxlength="40" placeholder="+15551234567" class="{{ $input }}">
                    </label>
                    <label class="block">Country code for numbers without + or 00
                        <input name="default_country_code" value="{{ old('default_country_code', $countryCode) }}" maxlength="5" placeholder="1" class="{{ $input }} w-24">
                    </label>
                </div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="mb-1 text-base font-semibold">Staff texts</legend>
                <label class="block">Staff phone numbers
                    <textarea name="staff_numbers" rows="3" placeholder="+15551234567" class="{{ $input }}">{{ old('staff_numbers', $staffNumbers) }}</textarea>
                    <span class="mt-1 block text-ink-500">One per line.</span>
                </label>
                <div class="grid gap-1 sm:grid-cols-2">
                    @foreach ($events as $key => $label)
                        <label class="flex items-center gap-2"><input type="checkbox" name="staff_events[]" value="{{ $key }}" @checked(in_array($key, old('staff_events', $staffEvents), true))> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="mb-1 text-base font-semibold">Client texts</legend>
                <p class="text-ink-600">Only to clients who agreed to account texts and have a phone number.</p>
                <div class="grid gap-1 sm:grid-cols-2">
                    @foreach ($clientEventLabels as $key => $label)
                        <label class="flex items-center gap-2"><input type="checkbox" name="client_events[]" value="{{ $key }}" @checked(in_array($key, old('client_events', $clientEvents), true))> {{ $label }}</label>
                    @endforeach
                </div>
            </fieldset>

            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save settings</button>
        </form>

        <form method="POST" action="{{ route('admin.sms.test') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Send a test</h2>
            <label class="block">Number
                <input name="to" required maxlength="40" placeholder="+15551234567" class="{{ $input }}">
            </label>
            <button class="w-full rounded-full px-4 py-2 font-medium text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50">Send test</button>
        </form>
    </div>

    <h2 class="mb-2 mt-8 text-lg font-semibold">Recent texts</h2>
    @if ($log->isEmpty())
        <p class="text-sm text-ink-500">None sent yet.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">When</th><th class="px-4 py-2">To</th><th class="px-4 py-2">Message</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($log as $text)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2 text-ink-500">{{ $text->created_at->format('M j, g:i a') }}</td>
                            <td class="px-4 py-2">{{ $text->to }}@if ($text->client)<a href="{{ route('admin.clients.show', $text->client) }}" class="block text-brand-600">{{ $text->client->fullName() }}</a>@endif</td>
                            <td class="px-4 py-2">{{ $text->message }}</td>
                            <td class="px-4 py-2">{{ ucfirst($text->status) }}@if ($text->error)<span class="block text-red-700">{{ $text->error }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin-layout>
