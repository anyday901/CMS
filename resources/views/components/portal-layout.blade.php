@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    @auth('client')
        <nav class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <a href="{{ route('portal.dashboard') }}" class="font-semibold">{{ config('app.name') }}</a>
                @foreach ([
                    'portal.dashboard' => 'Home',
                    'portal.services.index' => 'Services',
                    'portal.invoices.index' => 'Invoices',
                    'portal.account.edit' => 'Account',
                ] as $route => $label)
                    <a href="{{ route($route) }}"
                       class="text-sm {{ request()->routeIs(preg_replace('/\.(index|edit)$/', '.*', $route)) ? 'font-medium text-indigo-600' : 'text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
                @endforeach
                <form method="POST" action="{{ route('portal.logout') }}" class="ml-auto">
                    @csrf
                    <button class="text-sm text-gray-600 hover:text-gray-900">Log out</button>
                </form>
            </div>
        </nav>
    @endauth

    <main class="mx-auto max-w-5xl px-4 py-8">
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
