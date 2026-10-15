<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Set up CMS</title>
    @if (is_file(public_path('build/manifest.json')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body class="harbor-backdrop min-h-screen bg-ink-50 text-ink-900 antialiased">
    <main class="mx-auto max-w-2xl px-4 py-10">
        <p class="mb-6 flex items-center gap-2 font-display text-lg font-extrabold"><span class="brand-mark"></span>CMS setup</p>
        @yield('content')
    </main>
</body>
</html>
