@php
    $fields = ['username' => 'Username', 'password' => 'Password', 'api_token' => 'API token', 'ip_address' => 'IP address', 'assigned_ips' => 'Assigned IP addresses'];
    $chosenModule = old('module', $server->module ?? $modules->keys()->first());
    // What the script below needs to show each module's fields, help and default port.
    $setup = $modules->map(fn ($module) => ['fields' => $module->serverFields(), 'ports' => [$module->defaultPort(false), $module->defaultPort(true)]]);
@endphp
<x-admin-layout :title="$server->exists ? 'Edit server' : 'Add server'">
    <p class="mb-2 text-sm"><a href="{{ route('admin.servers.index') }}" class="text-brand-600">Servers</a></p>
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ $server->exists ? 'Edit '.$server->name : 'Add server' }}</h1>
        @if ($server->exists)
            <form method="POST" action="{{ route('admin.servers.check', $server) }}" class="ml-auto">@csrf
                <button class="rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Check status</button>
            </form>
            <form method="POST" action="{{ route('admin.servers.test', $server) }}">@csrf
                <button class="rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">Test connection</button>
            </form>
        @endif
    </div>
    @if (session('connection_error'))
        <div class="mb-6 max-w-3xl rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('connection_error') }}</div>
    @endif

    <form method="POST" action="{{ $server->exists ? route('admin.servers.update', $server) : route('admin.servers.store') }}" class="max-w-3xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @if ($server->exists) @method('PUT') @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">Name
                <input name="name" value="{{ old('name', $server->name) }}" required placeholder="Server 1" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Module
                <select name="module" id="server-module" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach ($modules as $key => $module)
                        <option value="{{ $key }}" @selected($chosenModule === $key)>{{ $module->label() }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="block sm:col-span-2">Hostname
                <input name="hostname" value="{{ old('hostname', $server->hostname) }}" required placeholder="server1.example.com" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Port
                <input type="number" name="port" id="server-port" min="1" max="65535" value="{{ old('port', $server->port) }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Blank for the module's usual port.</span>
            </label>
        </div>
        <input type="hidden" name="secure" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="secure" id="server-secure" value="1" @checked(old('secure', $server->secure))> Connect over HTTPS</label>
        <input type="hidden" name="verify_tls" value="0">
        <label class="flex items-start gap-2"><input type="checkbox" name="verify_tls" value="1" class="mt-0.5" @checked(old('verify_tls', $server->verify_tls))>
            <span>Check the server's certificate. Untick only for a self-signed certificate on a network you trust, such as a new Proxmox host.</span></label>

        @foreach ($fields as $name => $label)
            <label class="block" data-server-field="{{ $name }}">{{ $label }}
                @if ($name === 'assigned_ips')
                    <textarea name="assigned_ips" rows="4" placeholder="203.0.113.10/24 203.0.113.1" class="mt-1 block w-full rounded-md px-3 py-2 font-mono ring-1 ring-ink-300">{{ old('assigned_ips', $server->assigned_ips) }}</textarea>
                @elseif (in_array($name, ['password', 'api_token'], true))
                    @php $saved = filled($server->{$name}); @endphp
                    <input type="password" name="{{ $name }}" autocomplete="new-password" placeholder="{{ $saved ? 'Saved. Leave blank to keep it.' : '' }}" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @else
                    <input name="{{ $name }}" value="{{ old($name, $server->{$name}) }}" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @endif
                @foreach ($modules as $key => $module)
                    @if ($help = $module->serverFields()[$name]['help'] ?? null)
                        <span class="text-ink-500" data-help="{{ $key }}">{{ $help }}@if ($module->serverFields()[$name]['required'] ?? false) Required.@endif</span>
                    @endif
                @endforeach
            </label>
            @if (in_array($name, ['password', 'api_token'], true) && filled($server->{$name}))
                <label class="-mt-2 flex items-center gap-2 text-ink-600" data-server-field="{{ $name }}"><input type="checkbox" name="clear_{{ $name }}" value="1"> Remove the saved {{ $name === 'api_token' ? 'API token' : 'password' }}</label>
            @endif
        @endforeach

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">Maximum accounts
                <input type="number" name="max_accounts" min="1" value="{{ old('max_accounts', $server->max_accounts) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">@if ($server->exists){{ number_format($server->account_count) }} in use. @endif A full server gets no new accounts.</span>
            </label>
        </div>
        <input type="hidden" name="active" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $server->active))> Active: new accounts can go on this server</label>

        <fieldset class="space-y-3 border-t border-ink-100 pt-4">
            <legend class="font-semibold">Status monitoring</legend>
            <input type="hidden" name="monitor" value="0">
            <label class="flex items-start gap-2"><input type="checkbox" name="monitor" value="1" class="mt-0.5" @checked(old('monitor', $server->monitor))>
                <span>Check every five minutes that the server answers on its module's port, and email admins when it goes down or comes back.</span></label>
            <label class="block">Also check these ports
                <input name="monitor_ports" value="{{ old('monitor_ports', $server->monitor_ports) }}" placeholder="80, 443, 21, 25" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">Such as the web server (80, 443), FTP (21) and mail (25, 993). One that doesn't answer puts the server in warning.</span>
            </label>
            @error('monitor_ports')<p class="text-red-700">{{ $message }}</p>@enderror
        </fieldset>

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>

    @if ($server->exists && $server->account_count === 0 && $server->leftover_count === 0)
        <form method="POST" action="{{ route('admin.servers.destroy', $server) }}" class="mt-4" onsubmit="return confirm('Delete this server?')">
            @csrf @method('DELETE')
            <button class="text-sm text-red-600 hover:underline">Delete this server</button>
        </form>
    @elseif ($server->exists && $server->account_count === 0)
        <p class="mt-4 text-sm text-ink-500">{{ $server->leftover_count }} ended service(s) still have an account on this server to remove. Run their terminate again from the service page, then the server can be deleted.</p>
    @endif

    <script>
        // Show the fields and help of the chosen module, and its usual port.
        (() => {
            const setup = @json($setup);
            const select = document.getElementById('server-module');
            const secure = document.getElementById('server-secure');
            const port = document.getElementById('server-port');
            const sync = () => {
                const module = setup[select.value] || { fields: {}, ports: ['', ''] };
                document.querySelectorAll('[data-server-field]').forEach(el => { el.hidden = !(el.dataset.serverField in module.fields); });
                document.querySelectorAll('[data-help]').forEach(el => { el.hidden = el.dataset.help !== select.value; });
                document.querySelectorAll('[data-server-field] input[name="username"], [data-server-field] input[name="ip_address"]').forEach(input => {
                    input.required = Boolean(module.fields[input.name]?.required);
                });
                port.placeholder = module.ports[secure.checked ? 1 : 0];
            };
            select.addEventListener('change', sync);
            secure.addEventListener('change', sync);
            sync();
        })();
    </script>
</x-admin-layout>
