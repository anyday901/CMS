{{-- The server, domain and login of a service whose module uses servers. --}}
@php $saved = collect($service->provisioning_data ?? [])->filter(fn ($value) => is_scalar($value) && $value !== ''); @endphp
<dl class="mb-3 grid gap-3 sm:grid-cols-4">
    <div><dt class="text-ink-500">Server</dt>
        <dd>@if ($service->server)
                @can('manage-settings')<a href="{{ route('admin.servers.edit', $service->server) }}" class="text-brand-600">{{ $service->server->name }}</a>@else{{ $service->server->name }}@endcan
                <div class="text-ink-500">{{ $service->server->hostname }}</div>
            @else
                <span class="text-ink-500">Chosen when the service is created</span>
            @endif</dd></div>
    <div><dt class="text-ink-500">{{ $module->needsDomain() ? 'Domain' : 'Hostname' }}</dt><dd>{{ $service->label ?: '—' }}</dd></div>
    <div><dt class="text-ink-500">Username</dt><dd>{{ $service->username ?: '—' }}</dd></div>
    <div><dt class="text-ink-500">Password</dt>
        <dd>@if (blank($service->password))
                —
            @else
                @can('manage-clients')
                    <details><summary class="cursor-pointer text-brand-600">Show</summary><code class="break-all">{{ $service->password }}</code></details>
                @else
                    Set
                @endcan
            @endif</dd></div>
    @if ($saved->isNotEmpty())
        <div class="sm:col-span-4"><dt class="text-ink-500">Saved by the module</dt>
            <dd>{{ $saved->map(fn ($value, $key) => str_replace('_', ' ', $key).': '.$value)->join(', ') }}</dd></div>
    @endif
</dl>
@can('manage-clients')
    <details class="mb-3" @if ($errors->hasAny(['server_id', 'label', 'username', 'password'])) open @endif>
        <summary class="cursor-pointer text-brand-600">Change server or login</summary>
        <form method="POST" action="{{ route('admin.services.server', $service) }}" class="mt-3 grid max-w-3xl gap-4 sm:grid-cols-2">
            @csrf @method('PUT')
            <label class="block">Server
                <select name="server_id" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    <option value="">None yet: choose one when it's created</option>
                    @foreach ($servers as $server)
                        <option value="{{ $server->id }}" @selected((string) old('server_id', $service->server_id) === (string) $server->id)>{{ $server->name }} ({{ $server->hostname }})</option>
                    @endforeach
                </select>
                <span class="text-ink-500">Doesn't move the account; use it when it was moved another way.</span>
            </label>
            <label class="block">{{ $module->needsDomain() ? 'Domain' : 'Hostname' }}
                <input name="label" value="{{ old('label', $service->label) }}" placeholder="{{ $module->needsDomain() ? 'example.com' : '' }}" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Username
                <input name="username" value="{{ old('username', $service->username) }}" placeholder="Made from the domain when blank" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">New password
                <input type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep it" class="mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                @if (App\Provisioning\Provisioner::supports($module, 'change_password'))<span class="text-ink-500">Also set on the server once the account exists.</span>@endif
            </label>
            <div class="sm:col-span-2"><button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button></div>
        </form>
    </details>
@endcan
