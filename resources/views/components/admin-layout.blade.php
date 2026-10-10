@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }} Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    @auth
        <nav class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <a href="{{ route('admin.dashboard') }}" class="font-semibold">{{ config('app.name') }}</a>
                @php
                    $settings = auth()->user()->can('manage-settings');
                    $links = array_filter([
                        'admin.dashboard' => 'Dashboard',
                        'admin.clients.index' => 'Clients',
                        'admin.products.index' => $settings ? 'Products' : null,
                        'admin.invoices.index' => 'Invoices',
                        'admin.tax-rules.index' => $settings ? 'Tax' : null,
                        'admin.activity.index' => $settings ? 'Activity' : null,
                        'admin.settings.edit' => $settings ? 'Settings' : null,
                        'admin.staff.index' => auth()->user()->can('manage-staff') ? 'Staff' : null,
                    ]);
                @endphp
                @foreach ($links as $route => $label)
                    <a href="{{ route($route) }}"
                       class="text-sm {{ request()->routeIs(str_replace('.index', '.*', $route)) ? 'font-medium text-indigo-600' : 'text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
                @endforeach
                <form method="POST" action="{{ route('admin.logout') }}" class="ml-auto">
                    @csrf
                    <button class="text-sm text-gray-600 hover:text-gray-900">Log out {{ auth()->user()->name }}</button>
                </form>
            </div>
        </nav>
    @endauth

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
