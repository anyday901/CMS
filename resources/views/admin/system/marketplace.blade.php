<x-admin-layout title="Marketplace">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Marketplace</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')
    <p class="mb-4 max-w-3xl text-sm text-ink-600">Add-ons and themes made for CMS. Each download is checked against its checksum before it's unpacked. Packages run with full access to your data, like the rest of CMS, so only install ones you trust. Installed add-ons are switched on under <a href="{{ route('admin.addons.index') }}" class="text-brand-600">Add-ons</a>, and themes under <a href="{{ route('admin.appearance.edit') }}" class="text-brand-600">Appearance</a>.</p>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach (['' => 'Everything', 'addon' => 'Add-ons', 'theme' => 'Themes'] as $key => $label)
            <a href="{{ route('admin.marketplace.index', array_filter(['type' => $key])) }}" class="rounded-full px-3 py-1 {{ (string) $type === $key ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 ring-1 ring-ink-200 hover:bg-ink-100' }}">{{ $label }}</a>
        @endforeach
        <a href="{{ route('admin.marketplace.index', array_filter(['type' => $type, 'refresh' => 1])) }}" class="ml-auto text-brand-600">Refresh the list</a>
    </div>

    @if ($packages === null)
        <div class="mb-6 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">Couldn't read the marketplace at {{ $registry }} just now. Try again later, or upload a package below.</div>
    @elseif ($packages->isEmpty())
        <p class="mb-6 text-sm text-ink-600">Nothing listed here yet.</p>
    @else
        <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($packages as $package)
                @php
                    $have = $installed[$package['type'].':'.$package['key']] ?? null;
                    $newer = $have !== null && $have !== '' && version_compare($package['version'], $have, '>');
                @endphp
                <article class="flex flex-col gap-2 rounded-lg border border-ink-200 bg-white p-5 text-sm">
                    <div class="flex items-start gap-2">
                        <h2 class="text-base font-semibold">{{ $package['name'] }}</h2>
                        <span class="ml-auto rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-600">{{ $package['type'] === 'addon' ? 'Add-on' : 'Theme' }}</span>
                    </div>
                    <p class="grow text-ink-600">{{ $package['description'] }}</p>
                    <p class="text-xs text-ink-500">{{ $package['version'] }}@if ($package['author']) · {{ $package['author'] }}@endif @if ($package['homepage']) · <a href="{{ $package['homepage'] }}" class="text-brand-600" rel="noopener nofollow" target="_blank">Details</a>@endif</p>
                    @if (! $package['compatible'])
                        <p class="text-xs text-amber-900">Needs CMS {{ $package['requires'] }} or newer.</p>
                    @elseif ($have !== null && ! $newer)
                        <p class="text-xs font-medium text-green-800">Installed{{ $have ? ' ('.$have.')' : '' }}</p>
                    @elseif ($installs)
                        <form method="POST" action="{{ route('admin.marketplace.install') }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $package['type'] }}">
                            <input type="hidden" name="key" value="{{ $package['key'] }}">
                            <button class="rounded-full bg-brand-600 px-3 py-1 font-medium text-white hover:bg-brand-500">{{ $newer ? 'Update to '.$package['version'] : 'Install' }}</button>
                        </form>
                    @endif
                    @if ($have !== null && $installs && $marketplace->removable($package['type'], $package['key']))
                        <form method="POST" action="{{ route('admin.marketplace.destroy', [$package['type'], $package['key']]) }}" onsubmit="return confirm('{{ $package['type'] === 'addon' ? 'Remove the files of this add-on?' : 'Remove this theme?' }}')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-700">Remove</button>
                        </form>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    @if ($installs)
        <form method="POST" action="{{ route('admin.marketplace.upload') }}" enctype="multipart/form-data" class="max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            <h2 class="text-base font-semibold">Upload a package</h2>
            <p class="text-ink-600">A zip with an addon.json or theme.json, such as one a developer sent you or one you made with <code class="rounded bg-ink-100 px-1">php artisan marketplace:package</code>. Uploading a newer version of something installed replaces it.</p>
            <label class="block">Package zip (up to 7.5 MB)
                <input type="file" name="package" accept=".zip" required class="mt-1 block">
            </label>
            <button class="rounded-full bg-white px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Upload and install</button>
        </form>
    @else
        <p class="text-sm text-ink-600">Installing is turned off on this site{{ App\Support\Demo::enabled() ? ' because it is a demo' : ' (MARKETPLACE_INSTALLS=false)' }}.</p>
    @endif
</x-admin-layout>
