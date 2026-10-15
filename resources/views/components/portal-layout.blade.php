@props(['title' => null, 'store' => false])
@php
    $cartCount = $store || auth('client')->check() ? app(App\Ordering\Cart::class)->count() : 0;
    $portalContact = App\Auth\PortalContact::current();
    $otherAccounts = (bool) $portalContact?->hasOtherAccounts();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-ink-50 text-ink-900 antialiased {{ auth('client')->check() || $store ? '' : 'harbor-backdrop' }}">
    @if (App\Support\Impersonation::active())
        <div class="bg-sun-300/60 text-sm text-amber-900">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-2">
                <span>You're signed in as <strong>{{ auth('client')->user()->fullName() }}</strong> from the admin area. What you do here is logged under {{ auth('web')->user()->name }}.</span>
                <form method="POST" action="{{ route('portal.impersonation.stop') }}" class="ml-auto">
                    @csrf
                    <button class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Back to admin</button>
                </form>
            </div>
        </div>
    @endif
    @auth('client')
        <nav class="border-b border-ink-200 bg-white" aria-label="Portal">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3">
                <a href="{{ route('portal.dashboard') }}" class="mr-3 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</a>
                @foreach (array_filter([
                    'portal.dashboard' => 'Home',
                    'portal.services.index' => App\Auth\PortalContact::allows('services') ? 'Services' : null,
                    'portal.invoices.index' => App\Auth\PortalContact::allows('billing') ? 'Invoices' : null,
                    'portal.tickets.index' => App\Auth\PortalContact::allows('support') ? 'Support' : null,
                    'portal.account.edit' => $portalContact ? null : 'Account',
                    'portal.security.show' => 'Security',
                    'portal.affiliates.show' => ! $portalContact && (config('affiliates.enabled') || auth('client')->user()->affiliate) ? 'Affiliates' : null,
                    'store.index' => App\Auth\PortalContact::allows('orders') ? 'Order' : null,
                ] + ($portalContact ? [] : app(App\Addons\AddonManager::class)->portalLinks())) as $route => $label)
                    <a href="{{ route($route) }}"
                       class="text-sm {{ request()->routeIs(preg_replace('/\.(index|edit)$/', '.*', $route)) || ($route === 'portal.tickets.index' && request()->routeIs(...App\Http\Controllers\Help\KnowledgebaseController::HELP_ROUTES)) ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ $label }}</a>
                @endforeach
                @if ($otherAccounts)
                    <a href="{{ route('portal.accounts') }}" class="ml-auto rounded-full px-3 py-1 text-sm ring-1 ring-ink-200 {{ request()->routeIs('portal.accounts') ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}" title="Switch account">{{ $portalContact->accountName() }} <span aria-hidden="true">⇄</span><span class="sr-only">, switch account</span></a>
                @endif
                @if ($cartCount)
                    <a href="{{ route('cart.index') }}" class="{{ $otherAccounts ? '' : 'ml-auto' }} rounded-full px-3 py-1 text-sm {{ request()->routeIs('cart.*', 'checkout.*') ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">Cart ({{ $cartCount }})</a>
                @endif
                <form method="POST" action="{{ route('portal.logout') }}" class="{{ $cartCount || $otherAccounts ? '' : 'ml-auto' }}">
                    @csrf
                    <button class="rounded-full px-3 py-1 text-sm text-ink-600 hover:bg-ink-100 hover:text-ink-900">Log out{{ $portalContact ? ' '.$portalContact->first_name : '' }}</button>
                </form>
            </div>
        </nav>
    @elseif ($store)
        <nav class="border-b border-ink-200 bg-white" aria-label="Store">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3">
                <a href="{{ route('store.index') }}" class="mr-3 flex items-center gap-2 font-display text-base font-extrabold"><span class="brand-mark"></span>{{ config('app.name') }}</a>
                <a href="{{ route('store.index') }}" class="text-sm {{ request()->routeIs('store.*') ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">Products</a>
                <a href="{{ route('cart.index') }}" class="text-sm {{ request()->routeIs('cart.*', 'checkout.*') ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">Cart{{ $cartCount ? " ({$cartCount})" : '' }}</a>
                <a href="{{ route('kb.index') }}" class="text-sm {{ request()->routeIs(...App\Http\Controllers\Help\KnowledgebaseController::HELP_ROUTES) ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">Help</a>
                <a href="{{ route('portal.login') }}" class="ml-auto rounded-full px-3 py-1 text-sm text-ink-600 hover:bg-ink-100 hover:text-ink-900">Log in</a>
            </div>
        </nav>
    @endauth

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if (auth('client')->check() && ! $portalContact && ! auth('client')->user()->hasVerifiedEmail() && ! App\Support\Impersonation::active())
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">
                <span class="grow">Please confirm your email address, {{ auth('client')->user()->email }}, with the link we emailed you.</span>
                <form method="POST" action="{{ route('portal.verification.send') }}">
                    @csrf
                    <button class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-ink-300 hover:bg-ink-50">Send the link again</button>
                </form>
            </div>
        @endif
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
