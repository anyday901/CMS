<x-admin-layout title="Security">
    <div class="mb-6 flex flex-wrap items-baseline gap-3">
        <h1 class="text-2xl font-semibold">Security</h1>
        <a href="{{ route('admin.settings.edit') }}" class="text-sm text-brand-600">Back to settings</a>
    </div>

    <form method="POST" action="{{ route('admin.security.update') }}" class="mb-10 max-w-2xl space-y-6 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @method('PUT')

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">Failed logins</legend>
            <p class="text-ink-600">Applies to the portal and the admin area. A lockout only blocks the IP address the failed logins came from, so the real owner can still log in from elsewhere.</p>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block">Lock out after
                    <input type="number" name="lockout_attempts" min="1" max="100" required value="{{ old('lockout_attempts', $settings['lockout_attempts']) }}" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">failed logins, for
                    <input type="number" name="lockout_minutes" min="1" max="1440" required value="{{ old('lockout_minutes', $settings['lockout_minutes']) }}" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <span class="pb-2">minutes</span>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <label class="block">Ban an IP address after
                    <input type="number" name="ban_after_failures" min="1" max="10000" value="{{ old('ban_after_failures', $settings['ban_after_failures']) }}" placeholder="Off" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <label class="block">failed logins in an hour, for
                    <input type="number" name="ban_hours" min="1" max="8760" required value="{{ old('ban_hours', $settings['ban_hours']) }}" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <span class="pb-2">hours</span>
            </div>
            <p class="text-ink-500">Leave the ban count blank to turn automatic bans off.</p>
            <label class="block">Keep login history for
                <span class="flex items-center gap-2"><input type="number" name="login_history_days" min="1" max="3650" required value="{{ old('login_history_days', $settings['login_history_days']) }}" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300"> days</span>
            </label>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">Staff two-factor authentication</legend>
            <input type="hidden" name="staff_two_factor" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="staff_two_factor" value="1" class="mt-1" @checked(old('staff_two_factor', $settings['staff_two_factor']))>
                <span>Require it for every staff member. Anyone without it has to set it up under My account before they can do anything else.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="mb-1 text-base font-semibold">New device emails</legend>
            <input type="hidden" name="new_device_emails" value="0">
            <label class="flex items-start gap-2">
                <input type="checkbox" name="new_device_emails" value="1" class="mt-1" @checked(old('new_device_emails', $settings['new_device_emails']))>
                <span>Email clients and contacts when they sign in to the portal from a browser or device they haven't used before, with the time, browser and IP address, so they can act if it wasn't them. Change the wording in the <a href="{{ route('admin.email-templates.edit', 'new_device_login') }}" class="text-brand-600">New device sign-in</a> template.</span>
            </label>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="mb-1 text-base font-semibold">CAPTCHA</legend>
            <p class="text-ink-600">Stops bots on public forms. Create a site in the provider's dashboard for this domain and paste its two keys here.</p>
            <label class="block">Provider
                <select name="captcha_provider" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">Off</option>
                    @foreach (App\Security\Captcha::PROVIDERS as $key => $provider)
                        <option value="{{ $key }}" @selected(old('captcha_provider', $settings['captcha']['provider']) === $key)>{{ $provider['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">Site key
                    <input name="captcha_site_key" value="{{ old('captcha_site_key', $settings['captcha']['site_key']) }}" autocomplete="off" class="mt-1 w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
                </label>
                <label class="block">Secret key
                    <input type="password" name="captcha_secret_key" autocomplete="new-password" placeholder="{{ $hasSecret ? 'Saved. Leave blank to keep it' : '' }}" class="mt-1 w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
                </label>
            </div>
            <div>
                <p class="mb-1">Show it on</p>
                @foreach (App\Security\Captcha::FORMS as $key => $label)
                    <label class="flex items-center gap-2"><input type="checkbox" name="captcha_forms[]" value="{{ $key }}" @checked(in_array($key, old('captcha_forms', $settings['captcha']['forms'] ?? []), true))> {{ $label }}</label>
                @endforeach
            </div>
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save security settings</button>
    </form>

    <h2 id="bans" class="mb-3 text-lg font-semibold">IP bans</h2>
    <p class="mb-3 max-w-2xl text-sm text-ink-600">Banned addresses can't open any page, but payment and email webhooks still arrive. Your address is {{ $myIp }}.</p>
    <form method="POST" action="{{ route('admin.security.bans.store') }}" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
        @csrf
        <label class="block">IP address or range
            <input name="ip_address" value="{{ old('ip_address') }}" required placeholder="203.0.113.7 or 203.0.113.0/24" class="mt-1 block w-64 rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">
        </label>
        <label class="block">Reason
            <input name="reason" value="{{ old('reason') }}" maxlength="255" placeholder="Card testing" class="mt-1 block w-56 rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">For days
            <input type="number" name="days" min="1" max="3650" value="{{ old('days') }}" placeholder="Forever" class="mt-1 block w-24 rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <button class="rounded-full px-3 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Ban</button>
    </form>
    @if ($bans->isEmpty())
        <p class="mb-10 text-sm text-ink-500">No addresses are banned.</p>
    @else
        <div class="mb-10 overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Address</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">By</th><th class="px-4 py-2">Until</th><th class="px-4 py-2"></th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($bans as $ban)
                        <tr class="{{ $ban->expired() ? 'text-ink-400' : '' }}">
                            <td class="px-4 py-2 font-mono text-xs">{{ $ban->ip_address }}</td>
                            <td class="px-4 py-2">{{ $ban->reason ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $ban->user?->name ?? 'Automatic' }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ $ban->expires_at ? $ban->expires_at->format('M j, Y g:i A').($ban->expired() ? ' (ended)' : '') : 'Forever' }}</td>
                            <td class="px-4 py-2 text-right">
                                <form method="POST" action="{{ route('admin.security.bans.destroy', $ban) }}">@csrf @method('DELETE')
                                    <button class="rounded-full px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-100">Lift</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 id="logins" class="mb-3 text-lg font-semibold">Login history</h2>
    <form method="GET" action="{{ route('admin.security.show') }}" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
        <label class="block">Email or IP address
            <input name="q" value="{{ $filters['q'] ?? '' }}" class="mt-1 block w-64 rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">Where
            <select name="area" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                <option value="">Portal and admin</option>
                <option value="portal" @selected(($filters['area'] ?? '') === 'portal')>Portal</option>
                <option value="admin" @selected(($filters['area'] ?? '') === 'admin')>Admin</option>
            </select>
        </label>
        <label class="block">Result
            <select name="outcome" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                <option value="">Any</option>
                @foreach ($outcomes as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['outcome'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if (! empty($filters['client']))<input type="hidden" name="client" value="{{ $filters['client'] }}">@endif
        <button class="rounded-full px-3 py-2 ring-1 ring-ink-300 hover:bg-ink-100">Filter</button>
        @if (array_filter($filters))<a href="{{ route('admin.security.show') }}#logins" class="pb-2 text-brand-600">Clear</a>@endif
    </form>
    @include('security._logins', ['logins' => $logins, 'showWho' => true, 'banLinks' => true])
    <div class="mt-4">{{ $logins->fragment('logins')->links() }}</div>
</x-admin-layout>
