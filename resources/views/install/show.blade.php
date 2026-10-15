@extends('install._layout')
@php
    $input = 'mt-1 block w-full rounded-md px-3 py-2 ring-1 ring-ink-300';
    $steps = ['requirements' => 'Check the server', 'database' => 'Connect the database', 'account' => 'Create your account'];
    $value = fn (string $key, $default = '') => $old[$key] ?? $default;
@endphp
@section('content')
    <ol class="mb-6 flex flex-wrap gap-2 text-sm">
        @foreach ($steps as $key => $label)
            <li @class(['rounded-full px-3 py-1', 'bg-brand-600 font-semibold text-white' => $key === $step, 'bg-white text-ink-600 ring-1 ring-ink-200' => $key !== $step])>{{ $loop->iteration }}. {{ $label }}</li>
        @endforeach
    </ol>

    @if ($status)
        <div class="mb-6 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ $status }}</div>
    @endif
    @if ($installErrors->any())
        <div class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">@foreach ($installErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @if ($step === 'requirements')
            <h1 class="mb-2 text-2xl">Check the server</h1>
            <p class="mb-4 text-ink-600">Fix what's marked below, then reload this page.</p>
        @else
            <h2 class="mb-3 text-base font-semibold">Server</h2>
        @endif
        <ul class="mb-2 space-y-1">
            @foreach ($requirements as $requirement)
                <li class="flex gap-2"><span aria-hidden="true" class="{{ $requirement['ok'] ? 'text-green-700' : 'text-red-700' }}">{{ $requirement['ok'] ? '✓' : '✗' }}</span>
                    <span><strong class="font-medium">{{ $requirement['label'] }}</strong><span class="sr-only">: {{ $requirement['ok'] ? 'OK' : 'not OK' }}</span>. <span class="text-ink-600">{{ $requirement['detail'] }}</span></span></li>
            @endforeach
        </ul>
    </section>

    @if ($step === 'database')
        <form method="POST" action="{{ url('install/database') }}" class="mt-6 space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <h1 class="text-2xl">Connect the database</h1>
            <p class="text-ink-600">Create an empty database and a user for it first (for MySQL or MariaDB: <code class="rounded bg-ink-100 px-1">CREATE DATABASE cms CHARACTER SET utf8mb4;</code>). The settings are saved to .env, and the tables are created.</p>
            <label class="block">Database
                <select name="driver" class="{{ $input }}" onchange="document.getElementById('server-fields').hidden = this.value === 'sqlite'">
                    @foreach ($drivers as $key => $label)<option value="{{ $key }}" @selected($value('driver', 'mysql') === $key)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <div id="server-fields" class="grid gap-3 sm:grid-cols-3" @if ($value('driver', 'mysql') === 'sqlite') hidden @endif>
                <label class="block sm:col-span-2">Host
                    <input name="host" value="{{ $value('host', '127.0.0.1') }}" class="{{ $input }}">
                </label>
                <label class="block">Port
                    <input type="number" name="port" value="{{ $value('port', '3306') }}" class="{{ $input }}">
                </label>
                <label class="block">Username
                    <input name="username" value="{{ $value('username') }}" class="{{ $input }}" autocomplete="off">
                </label>
                <label class="block sm:col-span-2">Password
                    <input type="password" name="password" class="{{ $input }}" autocomplete="new-password">
                </label>
            </div>
            <label class="block">Database name
                <input name="database" value="{{ $value('database', 'cms') }}" required class="{{ $input }}">
                <span class="text-ink-500">For SQLite, a file name in the database folder, such as database.sqlite.</span>
            </label>
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Connect and create the tables</button>
        </form>
    @elseif ($step === 'account')
        <form method="POST" action="{{ url('install/finish') }}" class="mt-6 space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            <h1 class="text-2xl">Create your account</h1>
            <label class="block">Company name
                <input name="company" value="{{ $value('company') }}" required maxlength="255" class="{{ $input }}">
                <span class="text-ink-500">Shown in the portal, on invoices and in emails. You can change it, and add your logo and address, under Settings &gt; Appearance.</span>
            </label>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">Your name
                    <input name="name" value="{{ $value('name') }}" required maxlength="255" class="{{ $input }}">
                </label>
                <label class="block">Email
                    <input type="email" name="email" value="{{ $value('email') }}" required maxlength="255" class="{{ $input }}">
                </label>
                <label class="block">Password
                    <input type="password" name="password" required minlength="12" class="{{ $input }}" autocomplete="new-password">
                    <span class="text-ink-500">At least 12 characters.</span>
                </label>
                <label class="block">Password again
                    <input type="password" name="password_confirmation" required minlength="12" class="{{ $input }}" autocomplete="new-password">
                </label>
            </div>
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Finish setup</button>
        </form>
    @endif
@endsection
