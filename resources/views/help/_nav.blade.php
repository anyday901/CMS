{{-- The Support area's sections, shown above each help page and the portal's ticket pages. --}}
<nav class="mb-6 flex flex-wrap gap-1 border-b border-ink-200 pb-3 text-sm" aria-label="Support">
    @foreach ([
        'portal.tickets.index' => ['Tickets', 'portal.tickets.*'],
        'kb.index' => ['Knowledge base', 'kb.*'],
        'announcements.index' => ['Announcements', 'announcements.*'],
        'downloads.index' => ['Downloads', 'downloads.*'],
        'network.index' => ['Network status', 'network.*'],
    ] as $route => [$label, $pattern])
        <a href="{{ route($route) }}" class="{{ request()->routeIs($pattern) ? 'rounded-md bg-white px-3 py-1.5 font-semibold text-ink-900 ring-1 ring-ink-200' : 'rounded-md px-3 py-1.5 text-ink-600 hover:bg-white hover:text-ink-900' }}">{{ $label }}</a>
    @endforeach
</nav>
