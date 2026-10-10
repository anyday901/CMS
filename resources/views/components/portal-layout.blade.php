@props(['title' => null])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-900 antialiased {{ auth('client')->check() ? '' : 'harbor-backdrop' }}">
    @auth('client')
        <nav class="border-b border-ink-200 bg-white">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3">
                <a href="{{ route('portal.dashboard') }}" class="mr-3 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</a>
                @foreach ([
                    'portal.dashboard' => 'Home',
                    'portal.services.index' => 'Services',
                    'portal.invoices.index' => 'Invoices',
                    'portal.account.edit' => 'Account',
                ] as $route => $label)
                    <a href="{{ route($route) }}"
                       class="text-sm {{ request()->routeIs(preg_replace('/\.(index|edit)$/', '.*', $route)) ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ $label }}</a>
                @endforeach
                <form method="POST" action="{{ route('portal.logout') }}" class="ml-auto">
                    @csrf
                    <button class="rounded-full px-3 py-1 text-sm text-ink-600 hover:bg-ink-100 hover:text-ink-900">Log out</button>
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
