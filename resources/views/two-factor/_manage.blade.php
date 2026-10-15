{{-- $account (has two-factor), $setupSecret, $routes (route name prefix such as portal.security.two-factor) --}}
<section id="two-factor" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
    <div class="flex items-center gap-2">
        <h2 class="font-semibold">Two-factor authentication</h2>
        @if ($account->hasTwoFactor())
            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">On</span>
        @else
            <span class="rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">Off</span>
        @endif
    </div>

    @if (session('recovery_codes'))
        <div class="rounded-md bg-sun-300/40 p-4">
            <p class="mb-2 font-medium">Your recovery codes</p>
            <p class="mb-3 text-ink-700">Each one signs you in once if you lose your phone. Save them somewhere safe now: they won't be shown again.</p>
            <ul class="grid grid-cols-2 gap-1 font-mono">
                @foreach (session('recovery_codes') as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($account->hasTwoFactor())
        <p class="text-ink-600">Turned on {{ $account->two_factor_confirmed_at->format('M j, Y') }}. Logging in asks for a code from your authenticator app. {{ $account->recoveryCodesLeft() }} {{ Str::plural('recovery code', $account->recoveryCodesLeft()) }} left.</p>
        <form method="POST" action="{{ route($routes.'.recovery-codes') }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <label class="block grow">Your password<input type="password" name="current_password" required autocomplete="current-password" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"></label>
            <button class="rounded-full px-3 py-2 ring-1 ring-ink-300 hover:bg-ink-100">New recovery codes</button>
            <button formaction="{{ route($routes.'.disable') }}" name="_method" value="DELETE" class="rounded-full px-3 py-2 text-red-700 ring-1 ring-red-300 hover:bg-red-50">Turn off</button>
        </form>
    @elseif ($setupSecret)
        @php $uri = App\Auth\Totp::uri($setupSecret, $account->email, config('app.name')); @endphp
        <p class="text-ink-600">Scan this QR code with an authenticator app such as Google Authenticator, Microsoft Authenticator, Authy or 1Password, then type the code it shows.</p>
        <div class="w-fit rounded-md bg-white p-2 ring-1 ring-ink-200" aria-label="QR code">{!! App\Auth\Totp::qrSvg($uri) !!}</div>
        <p class="text-ink-600">Can't scan it? Enter this key in the app: <code class="rounded bg-ink-100 px-1 font-mono">{{ App\Auth\Totp::readable($setupSecret) }}</code></p>
        <form method="POST" action="{{ route($routes.'.confirm') }}" class="flex flex-wrap items-end gap-2">
            @csrf
            <label class="block">Code from the app
                <input name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="20" class="mt-1 block w-40 rounded-md px-3 py-2 font-mono tracking-widest ring-1 ring-ink-300">
            </label>
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Turn on</button>
            <button formaction="{{ route($routes.'.cancel') }}" formnovalidate name="_method" value="DELETE" class="rounded-full px-3 py-2 text-ink-600 hover:bg-ink-100">Cancel</button>
        </form>
    @else
        <p class="text-ink-600">Protect your login with a code from an authenticator app on your phone, as well as your password.</p>
        <form method="POST" action="{{ route($routes.'.start') }}">
            @csrf
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Set up two-factor</button>
        </form>
    @endif
</section>
