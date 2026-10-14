{{-- Sections of the admin Support area. --}}
<nav class="mb-6 flex flex-wrap gap-1 border-b border-ink-200 pb-3 text-sm" aria-label="Support">
    @foreach ([
        'admin.kb.index' => ['Knowledge base', ['admin.kb.*', 'admin.kb-*']],
        'admin.announcements.index' => ['Announcements', ['admin.announcements.*']],
        'admin.network-issues.index' => ['Network status', ['admin.network-issues.*']],
        'admin.downloads.index' => ['Downloads', ['admin.downloads.*', 'admin.download-categories.*']],
    ] as $route => [$label, $patterns])
        <a href="{{ route($route) }}" class="{{ request()->routeIs(...$patterns) ? 'rounded-md bg-white px-3 py-1.5 font-semibold text-ink-900 ring-1 ring-ink-200' : 'rounded-md px-3 py-1.5 text-ink-600 hover:bg-white hover:text-ink-900' }}">{{ $label }}</a>
    @endforeach
</nav>
