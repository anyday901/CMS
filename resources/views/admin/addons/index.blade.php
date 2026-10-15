<x-admin-layout title="Add-ons">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Add-ons</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Add-ons are optional modules that add a feature, such as project management. Install one to set up its tables, check its settings, then enable it. Developers can write their own; see <code class="rounded bg-ink-100 px-1">docs/addons.md</code>.</p>

    @if ($addons->isEmpty())
        <p class="text-sm text-ink-500">No add-ons are available. List add-on classes in <code class="rounded bg-ink-100 px-1">config/addons.php</code>.</p>
    @else
        <div class="space-y-3">
            @foreach ($addons as $key => $addon)
                @php
                    $record = $manager->installed()->get($key);
                    $outdated = $record && version_compare($addon->version(), $record->version, '>');
                @endphp
                <div class="flex flex-wrap items-start gap-4 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                    <div class="min-w-0 flex-1">
                        <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold">
                            {{ $addon->name() }}
                            <span class="text-xs font-normal text-ink-500">v{{ $record->version ?? $addon->version() }}{{ $addon->author() ? ' · '.$addon->author() : '' }}</span>
                            @if (! $record)
                                <span class="rounded-full bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-600">Not installed</span>
                            @elseif ($record->enabled)
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Enabled</span>
                            @else
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Disabled</span>
                            @endif
                        </h2>
                        <p class="mt-1 text-ink-600">{{ $addon->description() }}</p>
                        @if ($outdated)
                            <p class="mt-1 text-amber-700">Version {{ $addon->version() }} is available.</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! $record)
                            <form method="POST" action="{{ route('admin.addons.install', $key) }}">
                                @csrf
                                <button class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">Install</button>
                            </form>
                        @else
                            @if ($outdated)
                                <form method="POST" action="{{ route('admin.addons.upgrade', $key) }}">
                                    @csrf
                                    <button class="rounded-full bg-brand-600 px-4 py-1.5 font-medium text-white hover:bg-brand-500">Update</button>
                                </form>
                            @endif
                            <a href="{{ route('admin.addons.edit', $key) }}" class="rounded-full px-4 py-1.5 font-medium text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50">Configure</a>
                            <form method="POST" action="{{ route($record->enabled ? 'admin.addons.disable' : 'admin.addons.enable', $key) }}">
                                @csrf
                                <button class="rounded-full px-4 py-1.5 font-medium {{ $record->enabled ? 'text-ink-700 ring-1 ring-ink-300 hover:bg-ink-50' : 'bg-brand-600 text-white hover:bg-brand-500' }}">{{ $record->enabled ? 'Disable' : 'Enable' }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-admin-layout>
