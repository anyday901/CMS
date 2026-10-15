@props(['title' => null, 'store' => false])
@php
    $cartCount = $store || auth('client')->check() ? app(App\Ordering\Cart::class)->count() : 0;
    $portalContact = App\Auth\PortalContact::current();
    $otherAccounts = (bool) $portalContact?->hasOtherAccounts();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ App\Support\Branding::companyName() }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-head')
</head>
<body class="min-h-screen bg-ink-50 text-ink-900 antialiased {{ auth('client')->check() || $store ? '' : 'harbor-backdrop' }}">
    @if (App\Support\Demo::enabled())
        <div class="bg-brand-700 px-4 py-2 text-center text-sm text-white">{{ __('This is a demo. Sign in as :email with the password :password, and look around.', ['email' => config('demo.client_email'), 'password' => config('demo.password')]) }}</div>
    @endif
    @if (config('maintenance.enabled') && auth('web')->check())
        <div class="bg-sun-300/60 px-4 py-2 text-center text-sm text-amber-900">Maintenance mode is on. Visitors see the maintenance notice; you see the portal because you're signed in to the admin area.</div>
    @endif
    @if (App\Support\Impersonation::active())
        <div class="bg-sun-300/60 text-sm text-amber-900">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-2">
                <span>{!! __("You're signed in as :name from the admin area. What you do here is logged under :admin.", ['name' => '<strong>'.e(auth('client')->user()->fullName()).'</strong>', 'admin' => e(auth('web')->user()->name)]) !!}</span>
                <form method="POST" action="{{ route('portal.impersonation.stop') }}" class="ml-auto">
                    @csrf
                    <button class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-ink-300 hover:bg-ink-50">{{ __('Back to admin') }}</button>
                </form>
            </div>
        </div>
    @endif
    @auth('client')
        <nav class="border-b border-ink-200 bg-white" aria-label="{{ __('Portal') }}">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3">
                <a href="{{ route('portal.dashboard') }}" class="mr-3 flex items-center gap-2 font-display text-base font-extrabold">@if ($logo = App\Support\Branding::logoUrl())<img src="{{ $logo }}" alt="{{ App\Support\Branding::companyName() }}" class="h-8 w-auto max-w-48 object-contain">@else<span class="brand-mark"></span>{{ App\Support\Branding::companyName() }}@endif</a>
                @foreach (array_filter([
                    'portal.dashboard' => __('Home'),
                    'portal.services.index' => App\Auth\PortalContact::allows('services') ? __('Services') : null,
                    'portal.invoices.index' => App\Auth\PortalContact::allows('billing') ? __('Invoices') : null,
                    'portal.tickets.index' => App\Auth\PortalContact::allows('support') ? __('Support') : null,
                    'portal.account.edit' => $portalContact ? null : __('Account'),
                    'portal.security.show' => __('Security'),
                    'portal.affiliates.show' => ! $portalContact && (config('affiliates.enabled') || auth('client')->user()->affiliate) ? __('Affiliates') : null,
                    'store.index' => App\Auth\PortalContact::allows('orders') ? __('Order') : null,
                ] + ($portalContact ? [] : app(App\Addons\AddonManager::class)->portalLinks())) as $route => $label)
                    <a href="{{ route($route) }}"
                       class="text-sm {{ request()->routeIs(preg_replace('/\.(index|edit)$/', '.*', $route)) || ($route === 'portal.tickets.index' && request()->routeIs(...App\Http\Controllers\Help\KnowledgebaseController::HELP_ROUTES)) ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ __($label) }}</a>
                @endforeach
                @if ($otherAccounts)
                    <a href="{{ route('portal.accounts') }}" class="ml-auto rounded-full px-3 py-1 text-sm ring-1 ring-ink-200 {{ request()->routeIs('portal.accounts') ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}" title="{{ __('Switch account') }}">{{ $portalContact->accountName() }} <span aria-hidden="true">⇄</span><span class="sr-only">{{ __(', switch account') }}</span></a>
                @endif
                @if ($cartCount)
                    <a href="{{ route('cart.index') }}" class="{{ $otherAccounts ? '' : 'ml-auto' }} rounded-full px-3 py-1 text-sm {{ request()->routeIs('cart.*', 'checkout.*') ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ __('Cart (:count)', ['count' => $cartCount]) }}</a>
                @endif
                <form method="POST" action="{{ route('portal.logout') }}" class="{{ $cartCount || $otherAccounts ? '' : 'ml-auto' }}">
                    @csrf
                    <button class="rounded-full px-3 py-1 text-sm text-ink-600 hover:bg-ink-100 hover:text-ink-900">{{ $portalContact ? __('Log out :name', ['name' => $portalContact->first_name]) : __('Log out') }}</button>
                </form>
            </div>
        </nav>
    @elseif ($store)
        <nav class="border-b border-ink-200 bg-white" aria-label="{{ __('Store') }}">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3">
                <a href="{{ route('store.index') }}" class="mr-3 flex items-center gap-2 font-display text-base font-extrabold">@if ($logo = App\Support\Branding::logoUrl())<img src="{{ $logo }}" alt="{{ App\Support\Branding::companyName() }}" class="h-8 w-auto max-w-48 object-contain">@else<span class="brand-mark"></span>{{ App\Support\Branding::companyName() }}@endif</a>
                <a href="{{ route('store.index') }}" class="text-sm {{ request()->routeIs('store.*') ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ __('Products') }}</a>
                <a href="{{ route('cart.index') }}" class="text-sm {{ request()->routeIs('cart.*', 'checkout.*') ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ $cartCount ? __('Cart (:count)', ['count' => $cartCount]) : __('Cart') }}</a>
                <a href="{{ route('kb.index') }}" class="text-sm {{ request()->routeIs(...App\Http\Controllers\Help\KnowledgebaseController::HELP_ROUTES) ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ __('Help') }}</a>
                <a href="{{ route('portal.login') }}" class="ml-auto rounded-full px-3 py-1 text-sm text-ink-600 hover:bg-ink-100 hover:text-ink-900">{{ __('Log in') }}</a>
            </div>
        </nav>
    @endauth

    <main class="mx-auto max-w-5xl px-4 py-8">
        @if (auth('client')->check() && ! $portalContact && ! auth('client')->user()->hasVerifiedEmail() && ! App\Support\Impersonation::active())
            <div class="mb-6 flex flex-wrap items-center gap-3 rounded-md bg-sun-300/40 px-4 py-3 text-sm text-amber-900">
                <span class="grow">{{ __('Please confirm your email address, :email, with the link we emailed you.', ['email' => auth('client')->user()->email]) }}</span>
                <form method="POST" action="{{ route('portal.verification.send') }}">
                    @csrf
                    <button class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-ink-300 hover:bg-ink-50">{{ __('Send the link again') }}</button>
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

    @if (count(App\Support\Language::all()) > 1 && ! request()->routeIs(...App\Http\Controllers\Help\KnowledgebaseController::HELP_ROUTES))
        <form method="POST" action="{{ route('language') }}" class="mx-auto flex max-w-5xl items-center justify-center gap-1.5 px-4 pb-4 text-xs">
            @csrf
            <label for="portal-language" class="text-ink-500">{{ __('Language') }}</label>
            <select id="portal-language" name="language" onchange="this.form.submit()" class="rounded-md bg-white px-2 py-1 ring-1 ring-ink-300">
                @foreach (App\Support\Language::all() as $code)
                    <option value="{{ $code }}" lang="{{ $code }}" @selected(app()->getLocale() === $code)>{{ App\Support\Language::nativeName($code) }}</option>
                @endforeach
            </select>
            <noscript><button class="rounded-md px-2 py-1 ring-1 ring-ink-300">{{ __('Change') }}</button></noscript>
        </form>
    @endif
    @include('partials.portal-footer')
</body>
</html>
