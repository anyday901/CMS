<nav class="mb-6 flex flex-wrap gap-1 text-sm" aria-label="System">
    @foreach ([
        'admin.system.health' => 'Health',
        'admin.maintenance.edit' => 'Maintenance',
        'admin.backups.index' => 'Backups',
        'admin.system.logs.index' => 'Logs',
        'admin.system.php' => 'PHP info',
        'admin.cleanup.show' => 'Cleanup',
        'admin.updates.index' => 'Updates',
        'admin.marketplace.index' => 'Marketplace',
        'admin.whmcs-modules.index' => 'WHMCS modules',
    ] as $route => $label)
        <a href="{{ route($route) }}" class="rounded-full px-3 py-1 {{ request()->routeIs(str_replace('.index', '.*', $route)) ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 ring-1 ring-ink-200 hover:bg-ink-100' }}">{{ $label }}</a>
    @endforeach
</nav>
