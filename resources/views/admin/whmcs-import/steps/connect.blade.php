@php
    $connected = $wizard->connection();
    $env = $wizard->envConnection();
    $source = old('source', $connected['source'] ?? 'env');
    $field = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300';
@endphp

@if ($connected)
    <div class="mb-6 rounded-md bg-brand-50 px-4 py-3">
        <p class="mb-2 font-semibold text-brand-800">Connected. This WHMCS database has:</p>
        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ($connected['preview'] as $label => $count)
                <div><dt class="text-ink-500">{{ $label }}</dt><dd class="font-display text-lg font-bold">{{ $count === null ? 'n/a' : number_format($count) }}</dd></div>
            @endforeach
        </dl>
        <a href="{{ route('admin.whmcs-import.show', ['step' => 3]) }}" class="mt-3 inline-block rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Continue to the dry run</a>
    </div>
    <p class="mb-4 text-ink-600">Or connect to a different database:</p>
@else
    <p class="mb-4 text-ink-600">Tell the importer where the WHMCS database is. It only reads from it.</p>
@endif

<form method="POST" action="{{ route('admin.whmcs-import.connect') }}" class="group space-y-4">
    @csrf
    <fieldset class="space-y-2">
        <legend class="sr-only">Database</legend>
        <label class="flex items-start gap-2 rounded-md p-3 ring-1 ring-ink-200 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-500">
            <input type="radio" name="source" value="env" class="mt-1" @checked($source === 'env')>
            <span><strong>Use the WHMCS_DB_* settings in .env</strong><br>
                <span class="text-ink-600">{{ $env['driver'] ?? '' }} database <code>{{ $env['database'] ?? '' }}</code> on {{ $env['host'] ?? '' }}{{ isset($env['port']) ? ':'.$env['port'] : '' }} as {{ $env['username'] ?? '' }}</span></span>
        </label>
        <label class="flex items-start gap-2 rounded-md p-3 ring-1 ring-ink-200 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-500">
            <input type="radio" name="source" value="form" id="source-form" class="mt-1" @checked($source === 'form')>
            <span><strong>Enter the connection details here</strong><br>
                <span class="text-ink-600">Kept, encrypted, in your login session only, until you log out.</span></span>
        </label>
    </fieldset>

    <div class="hidden gap-3 group-has-[#source-form:checked]:grid sm:grid-cols-2">
        <label class="block">Database type
            <select name="driver" class="{{ $field }}">
                @foreach (['mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('driver', $connected['driver'] ?? 'mysql') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">Host
            <input name="host" value="{{ old('host', $connected['host'] ?? '127.0.0.1') }}" class="{{ $field }}">
        </label>
        <label class="block">Port
            <input type="number" name="port" value="{{ old('port', $connected['port'] ?? '3306') }}" class="{{ $field }}">
        </label>
        <label class="block">Database name
            <input name="database" value="{{ old('database', $connected['database'] ?? '') }}" placeholder="whmcs_copy" class="{{ $field }}">
        </label>
        <label class="block">Username
            <input name="username" value="{{ old('username', $connected['username'] ?? '') }}" autocomplete="off" class="{{ $field }}">
        </label>
        <label class="block">Password
            <input type="password" name="password" autocomplete="new-password" class="{{ $field }}">
        </label>
    </div>

    <label class="block">WHMCS attachments folder on this server <span class="text-ink-500">(optional)</span>
        <input name="attachments" value="{{ old('attachments', $connected['attachments'] ?? '') }}" placeholder="/var/www/whmcs/attachments" class="{{ $field }}">
        <span class="text-ink-500">Leave blank to skip ticket attachments. The report lists the files it skipped.</span>
    </label>

    <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Test the connection</button>
</form>
