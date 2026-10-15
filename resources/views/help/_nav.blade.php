{{-- The Support area's sections, shown above each help page and the portal's ticket pages. --}}
<nav class="mb-6 flex flex-wrap gap-1 border-b border-ink-200 pb-3 text-sm" aria-label="{{ __('Support') }}">
    @foreach ([
        ...(auth('client')->guest() && config('tickets.guest_tickets')
            ? ['tickets.guest.create' => [__('Contact us'), 'tickets.guest.*']]
            : ['portal.tickets.index' => [__('Tickets'), 'portal.tickets.*']]),
        'kb.index' => [__('Knowledge base'), 'kb.*'],
        'announcements.index' => [__('Announcements'), 'announcements.*'],
        'downloads.index' => [__('Downloads'), 'downloads.*'],
        'network.index' => [__('Network status'), 'network.*'],
    ] as $route => [$label, $pattern])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'rounded-md bg-white px-3 py-1.5 font-semibold text-ink-900 ring-1 ring-ink-200' : 'rounded-md px-3 py-1.5 text-ink-600 hover:bg-white hover:text-ink-900' }}">{{ $label }}</a>
    @endforeach
    @if (count(App\Support\Language::all()) > 1)
        <form method="POST" action="{{ route('help.language') }}" class="ml-auto flex items-center gap-1.5">
            @csrf
            <label for="help-language" class="text-ink-500">{{ __('Language') }}</label>
            <select id="help-language" name="language" onchange="this.form.submit()" class="rounded-md bg-white px-2 py-1 ring-1 ring-ink-300">
                @foreach (App\Support\Language::all() as $code)
                    <option value="{{ $code }}" lang="{{ $code }}" @selected(App\Support\Language::current() === $code)>{{ App\Support\Language::nativeName($code) }}</option>
                @endforeach
            </select>
            <noscript><button class="rounded-md px-2 py-1 ring-1 ring-ink-300">{{ __('Change') }}</button></noscript>
        </form>
    @endif
</nav>
