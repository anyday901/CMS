<x-admin-layout title="Updates">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Updates</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')

    @if ($status)
        <div @class(['mb-6 rounded-md px-4 py-3 text-sm', 'bg-sun-300/40 text-amber-900' => $status['state'] === 'running', 'bg-green-50 text-green-800' => $status['state'] === 'done', 'bg-red-50 text-red-800' => $status['state'] === 'failed'])>
            @if ($running)
                <script>setTimeout(() => window.location.reload(), 5000);</script>
                Updating to {{ $status['version'] }}: {{ $status['step'] }}…
            @elseif ($status['state'] === 'done')
                {{ $status['message'] }} ({{ now()->parse($status['finished_at'])->diffForHumans() }})
            @elseif ($status['state'] === 'failed')
                The update to {{ $status['version'] }} didn't finish, so the old version is still running: {{ $status['message'] }}
            @else
                The update to {{ $status['version'] }} stopped at "{{ $status['step'] }}". Check the queue worker, then try again.
            @endif
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-3">
        <section class="space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm md:col-span-2">
            <div class="flex flex-wrap items-center gap-3">
                <p>This is CMS <strong>{{ $version }}</strong>.
                    @if ($release === null)
                        Couldn't check GitHub for a newer release just now.
                    @elseif ($newer)
                        <strong>{{ $release['version'] }}</strong> is available{{ $release['published_at'] ? ', released '.now()->parse($release['published_at'])->toFormattedDateString() : '' }}.
                    @else
                        It's the newest release.
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.updates.check') }}" class="ml-auto">
                    @csrf
                    <button class="rounded-full bg-white px-3 py-1 ring-1 ring-ink-300 hover:bg-ink-50">Check again</button>
                </form>
            </div>

            @if ($newer)
                <div class="rounded-md border border-ink-200 p-4">
                    <h2 class="mb-2 font-semibold"><a href="{{ $release['url'] }}" class="text-brand-600" rel="noopener" target="_blank">{{ $release['name'] }}</a></h2>
                    <div class="prose-content text-ink-700">{!! $notes !!}</div>
                </div>

                @if ($blocker === null && ! $sync)
                    <form method="POST" action="{{ route('admin.updates.run') }}" onsubmit="return confirm('Update to {{ $release['version'] }} now? The database is backed up first, and the site is in maintenance mode for a minute or so.')">
                        @csrf
                        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500 disabled:opacity-50" @disabled($running)>Update to {{ $release['version'] }}</button>
                    </form>
                    <p class="text-ink-500">The queue worker downloads the release, checks its checksum, backs up the database and the current files, copies the new files in and updates the database. If anything fails, the old version is put back.</p>
                @elseif ($blocker === null)
                    <p class="text-ink-600">This site has no queue worker (the queue is <code class="rounded bg-ink-100 px-1">sync</code>), so update from the command line: <code class="rounded bg-ink-100 px-1">php artisan cms:update</code>.</p>
                @else
                    <p class="text-ink-600">{{ $blocker }}</p>
                @endif
            @endif
        </section>

        <aside class="space-y-3 self-start rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <h2 class="text-base font-semibold">Updating by hand</h2>
            <p class="text-ink-600">From a release download, on the server:</p>
            <pre class="overflow-x-auto rounded bg-ink-100 p-2 text-xs">php artisan cms:update</pre>
            <p class="text-ink-600">From a git checkout:</p>
            <pre class="overflow-x-auto rounded bg-ink-100 p-2 text-xs">php artisan backup:run
php artisan down
git pull
composer install --no-dev -o
npm ci &amp;&amp; npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
php artisan up</pre>
            <p class="text-ink-500">Releases come from <a href="https://github.com/{{ $repository }}/releases" class="text-brand-600" rel="noopener" target="_blank">github.com/{{ $repository }}</a>. See docs/upgrading.md.</p>
        </aside>
    </div>
</x-admin-layout>
