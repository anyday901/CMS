<x-admin-layout title="WHMCS modules">
    <div class="mb-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">WHMCS modules</h1>
        <a href="{{ route('admin.settings.edit') }}" class="ml-auto text-sm text-brand-600">Back to settings</a>
    </div>
    @include('admin.system._nav')
    <p class="mb-4 max-w-3xl text-sm text-ink-600">Upload a WHMCS server (provisioning) module and use it on products like the built-in ones. CMS hands it the same details WHMCS would and runs its create, suspend, unsuspend, terminate, password, package, connection test and single sign-on functions. Each upload is checked first, and a new module stays off until you enable it. Once a module works, <strong>Convert to a CMS module</strong> turns it into a CMS module class you can keep and edit, with named settings; the code inside its functions is copied as it is. A module runs with full access to your data, like the rest of CMS, so only upload ones you trust.</p>

    @forelse ($modules as $module)
        @php($report = $module->report)
        <article class="mb-4 max-w-4xl rounded-lg border border-ink-200 bg-white p-5 text-sm">
            <div class="flex flex-wrap items-start gap-2">
                <div>
                    <h2 class="text-base font-semibold">{{ $module->display_name }}</h2>
                    <p class="text-xs text-ink-500">{{ $module->name }} · updated {{ $module->updated_at->diffForHumans() }}</p>
                </div>
                <span class="ml-auto rounded-full px-2 py-0.5 text-xs {{ $module->enabled ? 'bg-green-100 text-green-800' : 'bg-ink-100 text-ink-600' }}">{{ $module->enabled ? 'Enabled' : 'Off' }}</span>
            </div>

            <dl class="mt-3 grid gap-2 sm:grid-cols-[10rem_1fr]">
                <dt class="text-ink-500">Used here</dt>
                <dd>{{ implode(', ', $report['functions'] ?? []) ?: 'None' }}</dd>
                @if (! empty($report['unsupported']))
                    <dt class="text-ink-500">Not used here</dt>
                    <dd>{{ implode(', ', $report['unsupported']) }} <span class="text-ink-500">(custom buttons, client area pages and other WHMCS-only functions)</span></dd>
                @endif
                @if ($module->enabled)
                    <dt class="text-ink-500">Product settings</dt>
                    <dd>{{ collect($module->meta['config_fields'] ?? [])->pluck('label')->join(', ') ?: 'None' }}</dd>
                    <dt class="text-ink-500">Servers</dt>
                    <dd>{{ ($module->meta['requires_server'] ?? true) ? 'Uses servers added under Products > Servers' : 'Doesn\'t use a server' }}</dd>
                @endif
            </dl>

            @if (! empty($report['warnings']))
                <div class="mt-3 rounded-md bg-sun-300/40 px-4 py-3 text-amber-900">
                    <p class="font-medium">Things that may not work</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($report['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <p class="mt-3 text-green-800">The check found nothing that won't work here.</p>
            @endif

            <div class="mt-4 flex flex-wrap items-center gap-3">
                @if (! $module->enabled && $uploads)
                    <form method="POST" action="{{ route('admin.whmcs-modules.enable', $module) }}">
                        @csrf
                        <button class="rounded-full bg-brand-600 px-3 py-1 font-medium text-white hover:bg-brand-500">Enable</button>
                    </form>
                @endif
                @if ($module->enabled && $uploads)
                    <form method="POST" action="{{ route('admin.whmcs-modules.convert', $module) }}" onsubmit="return confirm('Convert {{ $module->display_name }} into a CMS module? Its products and servers move to the new module, and the uploaded WHMCS module is removed.')">
                        @csrf
                        <button class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Convert to a CMS module</button>
                    </form>
                @endif
                @if (($module->meta['requires_server'] ?? true))
                    <form method="POST" action="{{ route('admin.whmcs-modules.update', $module) }}" class="flex items-center gap-2">
                        @csrf @method('PUT')
                        <label class="flex items-center gap-1"><input type="checkbox" name="needs_domain" value="1" @checked($module->needs_domain)> Orders ask for a domain name</label>
                        <button class="text-brand-600">Save</button>
                    </form>
                @endif
                @if ($inUse($module))
                    <span class="ml-auto text-xs text-ink-500">In use by products or servers</span>
                @else
                    @if ($module->enabled)
                        <form method="POST" action="{{ route('admin.whmcs-modules.disable', $module) }}" class="ml-auto">
                            @csrf
                            <button class="text-ink-600">Turn off</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.whmcs-modules.destroy', $module) }}" @class(['ml-auto' => ! $module->enabled]) onsubmit="return confirm('Remove this module and its files?')">
                        @csrf @method('DELETE')
                        <button class="text-red-700">Remove</button>
                    </form>
                @endif
            </div>
        </article>
    @empty
        <p class="mb-6 text-sm text-ink-600">No WHMCS modules uploaded yet.</p>
    @endforelse

    @if ($converted->isNotEmpty())
        <h2 class="mb-2 mt-8 text-lg font-semibold">Converted modules</h2>
        <p class="mb-4 max-w-3xl text-sm text-ink-600">WHMCS modules converted into CMS module classes, in extensions/modules. Download one to keep its code with your site, or to change it like any other module.</p>
        @foreach ($converted as $manifest)
            <article class="mb-3 flex max-w-4xl flex-wrap items-center gap-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
                <div>
                    <p class="font-semibold">{{ $manifest['class'] }}</p>
                    <p class="text-xs text-ink-500">{{ $manifest['key'] }} · from the WHMCS module {{ $manifest['whmcs_name'] }}@if (! empty($manifest['converted_at'])) · converted {{ \Illuminate\Support\Carbon::parse($manifest['converted_at'])->toFormattedDateString() }}@endif</p>
                </div>
                <a href="{{ route('admin.converted-modules.download', $manifest['key']) }}" class="ml-auto text-brand-600">Download</a>
                @if ($convertedInUse($manifest['key']))
                    <span class="text-xs text-ink-500">In use by products or servers</span>
                @else
                    <form method="POST" action="{{ route('admin.converted-modules.destroy', $manifest['key']) }}" onsubmit="return confirm('Remove this converted module and its files?')">
                        @csrf @method('DELETE')
                        <button class="text-red-700">Remove</button>
                    </form>
                @endif
            </article>
        @endforeach
    @endif

    @if ($uploads)
        <form method="POST" action="{{ route('admin.whmcs-modules.store') }}" enctype="multipart/form-data" class="mt-6 max-w-2xl space-y-3 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            <h2 class="text-base font-semibold">Upload a module</h2>
            <p class="text-ink-600">The module's PHP file (such as mypanel.php), or a zip of its folder or of WHMCS's modules folder with it in modules/servers. Uploading a module again replaces its files. Payment gateway, registrar and add-on modules can't be uploaded yet, and encoded (ionCube) modules can't be checked, so they aren't accepted.</p>
            <label class="block">PHP file or zip (up to 7.5 MB)
                <input type="file" name="module" accept=".php,.zip" required class="mt-1 block">
            </label>
            <button class="rounded-full bg-white px-4 py-2 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Upload and check</button>
        </form>
    @else
        <p class="text-sm text-ink-600">Uploading WHMCS modules is turned off on this site{{ App\Support\Demo::enabled() ? ' because it is a demo' : ' (WHMCS_MODULE_UPLOADS=false)' }}.</p>
    @endif
</x-admin-layout>
