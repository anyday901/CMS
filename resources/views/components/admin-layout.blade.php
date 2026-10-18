@props(['title' => null, 'wide' => false])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' · ' : '' }}{{ App\Support\Branding::adminName() }} Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme-head', ['admin' => true])
</head>
<body class="min-h-screen bg-ink-50 text-ink-900 antialiased {{ auth()->check() ? '' : 'harbor-backdrop' }}">
    @if (App\Support\Demo::enabled())
        <div class="bg-brand-700 px-4 py-2 text-center text-sm text-white">This is a demo. Look around and change anything; it resets every few hours, and no email is sent.</div>
    @endif
    @if (config('maintenance.enabled') && auth()->check())
        <div class="bg-sun-300/60 px-4 py-2 text-center text-sm text-amber-900">Maintenance mode is on: visitors see the maintenance notice. <a href="{{ route('admin.maintenance.edit') }}" class="font-medium underline">Turn it off</a></div>
    @endif
    @auth
        <nav class="border-b border-ink-200 bg-white" aria-label="Admin">
            <div class="mx-auto flex {{ $wide ? 'max-w-[96rem]' : 'max-w-6xl' }} flex-wrap items-center gap-x-1 gap-y-2 px-4 py-3">
                <a href="{{ route('admin.dashboard') }}" class="mr-3 flex items-center gap-2 font-display text-base font-extrabold">@if ($logo = App\Support\Branding::logoUrl())<img src="{{ $logo }}" alt="" class="h-7 w-auto max-w-40 object-contain">@else<span class="brand-mark"></span>@endif{{ App\Support\Branding::adminName() }}</a>
                @php
                    $settings = auth()->user()->can('manage-settings');
                    $links = array_filter([
                        'admin.dashboard' => 'Dashboard',
                        'admin.clients.index' => 'Clients',
                        'admin.products.index' => $settings ? 'Products' : null,
                        'admin.domains.index' => App\Models\Tld::exists() || App\Models\Domain::exists() ? 'Domains' : null,
                        'admin.orders.index' => 'Orders'.(($pendingOrders = App\Models\Order::where('status', App\Enums\OrderStatus::Pending)->count()) ? " ({$pendingOrders})" : ''),
                        'admin.invoices.index' => 'Invoices',
                        'admin.quotes.index' => auth()->user()->can('manage-billing') || App\Models\Quote::exists() ? 'Quotes' : null,
                        'admin.tickets.index' => auth()->user()->can('manage-tickets') ? 'Tickets'.(($waiting = App\Models\Ticket::awaitingStaff()->count()) ? " ({$waiting})" : '') : null,
                        'admin.kb.index' => auth()->user()->can('manage-tickets') ? 'Support' : null,
                        'admin.tasks.index' => 'Tasks'.(($openTasks = App\Models\FulfillmentTask::open()->count()) ? " ({$openTasks})" : ''),
                        'admin.todos.index' => 'To-do'.(($myTodos = App\Models\Todo::unfinished()->where('user_id', auth()->id())->count()) ? " ({$myTodos})" : ''),
                        'admin.affiliates.index' => auth()->user()->can('manage-billing') && (config('affiliates.enabled') || App\Models\Affiliate::exists()) ? 'Affiliates' : null,
                        'admin.reports.index' => auth()->user()->can('view-reports') ? 'Reports' : null,
                        'admin.tax-rules.index' => $settings ? 'Tax' : null,
                        'admin.activity.index' => $settings ? 'Activity' : null,
                        'admin.emails.index' => $settings ? 'Emails' : null,
                        'admin.settings.edit' => $settings ? 'Settings' : null,
                        'admin.system.health' => ! $settings && auth()->user()->can('manage-system') ? 'System' : null,
                        'admin.staff.index' => auth()->user()->can('manage-staff') ? 'Staff' : null,
                    ] + app(App\Addons\AddonManager::class)->adminLinks(auth()->user()));
                @endphp
                @php
                    // Pages that light up a menu item besides its own.
                    $alsoActive = [
                        'admin.clients.index' => ['admin.mass-mail.*'],
                        'admin.settings.edit' => ['admin.mail.*', 'admin.appearance.*', 'admin.languages.*', 'admin.system.*', 'admin.maintenance.*', 'admin.backups.*', 'admin.updates.*', 'admin.marketplace.*', 'admin.whmcs-import.*', 'admin.security.*', 'admin.client-groups.*', 'admin.automation.*', 'admin.cron.*', 'admin.currencies.*', 'admin.payment-gateways.*', 'admin.tlds.*', 'admin.domain-settings.*', 'admin.addons.*', 'admin.webhooks.*', 'admin.chat-channels.*', 'admin.sms.*', 'admin.api-keys.*'],
                        'admin.kb.index' => ['admin.kb-*', 'admin.announcements.*', 'admin.network-issues.*', 'admin.downloads.*', 'admin.download-categories.*'],
                    ];
                @endphp
                @foreach ($links as $route => $label)
                    <a href="{{ route($route) }}"
                       class="text-sm {{ request()->routeIs(str_replace('.index', '.*', $route), ...$alsoActive[$route] ?? []) ? 'rounded-full bg-brand-100 px-3 py-1 font-semibold text-brand-700' : 'rounded-full px-3 py-1 text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ $label }}</a>
                @endforeach
                <form method="GET" action="{{ route('admin.search') }}" role="search" class="ml-auto">
                    <label class="sr-only" for="admin-search">Search everything</label>
                    <input id="admin-search" type="search" name="q" value="{{ request()->routeIs('admin.search') ? request('q') : '' }}" placeholder="Search…" class="w-40 rounded-full bg-ink-50 px-3 py-1 text-sm ring-1 ring-ink-200 focus:w-56 focus:bg-white">
                </form>
                <a href="{{ route('admin.account.show') }}" class="rounded-full px-3 py-1 text-sm {{ request()->routeIs('admin.account.*') ? 'bg-brand-100 font-semibold text-brand-700' : 'text-ink-600 hover:bg-ink-100 hover:text-ink-900' }}">{{ auth()->user()->name }}</a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="rounded-full px-3 py-1 text-sm text-ink-600 hover:bg-ink-100 hover:text-ink-900">Log out</button>
                </form>
            </div>
        </nav>
    @endauth

    <main class="mx-auto {{ $wide ? 'max-w-[96rem]' : 'max-w-6xl' }} px-4 py-8">
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
