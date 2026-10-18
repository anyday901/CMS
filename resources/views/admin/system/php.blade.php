<x-admin-layout title="PHP info">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">PHP info</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')
    <p class="mb-6 max-w-3xl text-sm text-ink-600">How the PHP that runs CMS is set up. The web server and the command line (cron and the queue worker) can use different PHP settings, so a limit here may differ from <code class="rounded bg-ink-100 px-1">php -i</code> on the server. Environment variables are left out, since they hold the secrets in .env.</p>

    <div class="mb-6 grid gap-6 md:grid-cols-3">
        <section class="rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2">
            <h2 class="mb-3 text-base font-semibold">Settings</h2>
            <dl class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-x-4 gap-y-1">
                <dt class="text-ink-500">PHP version</dt><dd>{{ $summary['version'] }}</dd>
                <dt class="text-ink-500">Server API</dt><dd>{{ $summary['sapi'] }}</dd>
                <dt class="text-ink-500">Operating system</dt><dd>{{ $summary['os'] }}</dd>
                <dt class="text-ink-500">Zend Engine</dt><dd>{{ $summary['zend'] }}</dd>
                <dt class="text-ink-500">php.ini</dt><dd class="break-all font-mono text-xs">{{ $summary['ini'] ?? 'None loaded' }}</dd>
                @foreach ($summary['settings'] as $key => $setting)
                    <dt class="text-ink-500">{{ $setting['label'] }} <code class="text-xs">{{ $key }}</code></dt><dd class="break-all">{{ $setting['value'] }}</dd>
                @endforeach
            </dl>
        </section>
        <section class="self-start rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <h2 class="mb-3 text-base font-semibold">Extensions ({{ count($summary['extensions']) }})</h2>
            <p class="leading-relaxed">{{ implode(', ', $summary['extensions']) }}</p>
        </section>
    </div>

    <section class="rounded-lg border border-ink-200 bg-white text-sm">
        <h2 class="border-b border-ink-200 px-6 py-4 text-base font-semibold">phpinfo()</h2>
        <style>
            .phpinfo { overflow-x: auto; padding: 1rem 1.5rem; font-size: 0.75rem; }
            .phpinfo table { width: 100%; margin-bottom: 1rem; border-collapse: collapse; table-layout: fixed; }
            .phpinfo td, .phpinfo th { border: 1px solid #e2e8f0; padding: 0.25rem 0.5rem; vertical-align: top; overflow-wrap: anywhere; }
            .phpinfo .e { width: 30%; background: #f8fafc; font-weight: 600; }
            .phpinfo .h { background: #f1f5f9; }
            .phpinfo h1, .phpinfo h2 { margin: 1rem 0 0.5rem; font-size: 1rem; font-weight: 600; }
            .phpinfo img { display: none; }
            .phpinfo pre { white-space: pre-wrap; }
        </style>
        {{-- phpinfo() escapes every value in its HTML; on the command line it's escaped text in a <pre>. --}}
        <div class="phpinfo">{!! $phpinfo !!}</div>
    </section>
</x-admin-layout>
