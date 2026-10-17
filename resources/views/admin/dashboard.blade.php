@php use App\Support\Money; use App\Support\SystemOverview; @endphp
<x-admin-layout title="Dashboard" :wide="true">
    @php
        $user = auth()->user();
        $panel = 'rounded-lg border border-ink-200 bg-white';
        $panelTitle = 'border-b border-ink-100 px-4 py-2.5 text-sm font-semibold text-brand-700';
        $sideTitle = 'mb-2 font-display text-sm font-bold text-ink-700';
        $sideLink = 'block rounded px-1 py-0.5 text-sm text-brand-700 hover:bg-ink-100';
    @endphp

    <div class="flex flex-col gap-6 lg:flex-row">
        {{-- Sidebar, as in WHMCS: shortcuts, system information, advanced search and staff online. --}}
        <aside class="order-last space-y-5 lg:order-first lg:w-56 lg:shrink-0">
            <section>
                <h2 class="{{ $sideTitle }}">Shortcuts</h2>
                <nav class="space-y-0.5" aria-label="Shortcuts">
                    @can('manage-clients')
                        <a href="{{ route('admin.clients.create') }}" class="{{ $sideLink }}">Add New Client</a>
                    @endcan
                    <a href="{{ route('admin.orders.index') }}" class="{{ $sideLink }}">View Orders</a>
                    <a href="{{ route('admin.todos.index') }}" class="{{ $sideLink }}">Create New To-Do Entry</a>
                    @can('manage-tickets')
                        <a href="{{ route('admin.tickets.index') }}" class="{{ $sideLink }}">Support Tickets</a>
                    @endcan
                    @if (App\Models\Tld::exists())
                        <a href="{{ route('domains.index') }}" class="{{ $sideLink }}">WHOIS Lookup</a>
                    @endif
                    <a href="{{ route('admin.invoices.index', ['status' => 'overdue']) }}" class="{{ $sideLink }}">Overdue Invoices</a>
                    @can('manage-clients')
                        <a href="{{ route('admin.mass-mail.index') }}" class="{{ $sideLink }}">Send Mass Mail</a>
                    @endcan
                    @can('manage-settings')
                        <a href="{{ route('admin.cron.show') }}" class="{{ $sideLink }}">Automation Status</a>
                    @endcan
                </nav>
            </section>

            <section>
                <h2 class="{{ $sideTitle }}">System Information</h2>
                <dl class="space-y-0.5 text-xs text-ink-600">
                    <div>Registered To: {{ App\Support\Branding::companyName() }}</div>
                    <div>License: AGPL-3.0-or-later (free, no key)</div>
                    <div>Version: {{ $version }}</div>
                </dl>
                @if ($newerRelease)
                    @can('manage-system')
                        <a href="{{ route('admin.updates.index') }}" class="mt-1 block text-xs font-semibold text-red-700">An update is available: {{ $newerRelease }}</a>
                    @else
                        <p class="mt-1 text-xs font-semibold text-red-700">An update is available: {{ $newerRelease }}</p>
                    @endcan
                @endif
            </section>

            <section>
                <h2 class="{{ $sideTitle }}">Advanced Search</h2>
                <form method="GET" action="{{ route('admin.search') }}" role="search" class="space-y-2">
                    <label class="sr-only" for="dashboard-search">Search clients, services, domains, invoices, orders and tickets</label>
                    <input id="dashboard-search" type="search" name="q" placeholder="Name, email, invoice #…" class="w-full rounded-md border-ink-200 bg-white px-2 py-1 text-sm ring-1 ring-ink-200">
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ route('admin.clients.index') }}" class="text-xs text-brand-700">Client filters</a>
                        <button class="rounded-md bg-ink-100 px-3 py-1 text-sm font-medium text-ink-700 hover:bg-ink-200">Search</button>
                    </div>
                </form>
            </section>

            <section>
                <h2 class="{{ $sideTitle }}">Staff Online</h2>
                <ul class="space-y-0.5 text-sm text-ink-600">
                    @foreach ($staffOnline as $staff)
                        <li>{{ $staff->name }}</li>
                    @endforeach
                </ul>
            </section>
        </aside>

        <div class="min-w-0 flex-1">
            <h1 class="mb-5 text-2xl font-semibold">Dashboard</h1>

            @if ($serverProblems->isNotEmpty())
                <div class="mb-5 space-y-2">
                    @foreach ($serverProblems as $server)
                        <a href="{{ route('admin.servers.index') }}" class="block rounded-md px-4 py-3 text-sm {{ $server->status === App\Models\Server::DOWN ? 'bg-red-50 text-red-800' : 'bg-orange-50 text-orange-800' }}">
                            <strong>{{ $server->name }} is {{ strtolower($server->statusLabel()) }}</strong> since {{ $server->status_since?->format('M j, g:ia') }}.@if ($server->status_detail) {{ $server->status_detail }}@endif
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- The four counts across the top. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Pending Orders', $pendingOrderCount, route('admin.orders.index'), 'bg-brand-600', 'bg-brand-700', 'M3 4h2l2.4 10.2a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 7H6.2M9 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm9 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z'],
                    ['Tickets Waiting', $waitingTicketCount, $user->can('manage-tickets') ? route('admin.tickets.index') : null, 'bg-ink-700', 'bg-ink-800', 'M4 5h16v11H8l-4 4V5Z'],
                    ['Pending Cancellations', $pendingCancellationCount, ($user->can('view-reports') ? route('admin.reports.show', 'suspensions-and-cancellations') : null), 'bg-amber-600', 'bg-amber-700', 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18ZM5.6 5.6l12.8 12.8'],
                    ['Pending Module Actions', $openTaskCount, route('admin.tasks.index'), 'bg-brand-400', 'bg-brand-500', 'M12 3 2 20h20L12 3Zm0 6v5m0 3v.5'],
                ] as [$label, $count, $link, $tone, $iconTone, $icon])
                    <a @if ($link) href="{{ $link }}" @endif class="flex overflow-hidden rounded-lg text-white {{ $tone }} {{ $link ? 'hover:opacity-95' : '' }}">
                        <span class="flex w-20 shrink-0 items-center justify-center {{ $iconTone }}" aria-hidden="true">
                            <svg viewBox="0 0 24 24" class="h-9 w-9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $icon }}" /></svg>
                        </span>
                        <span class="px-4 py-3">
                            <span class="block font-display text-3xl font-bold">{{ number_format($count) }}</span>
                            <span class="block text-xs text-white/85">{{ $label }}</span>
                        </span>
                    </a>
                @endforeach
            </div>

            <div class="mt-5 grid grid-cols-1 gap-5 xl:grid-cols-3">
                <div class="space-y-5 xl:col-span-2">
                    <section class="{{ $panel }}">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ink-100 px-4 py-2">
                            <h2 class="text-sm font-semibold text-brand-700">System Overview</h2>
                            <nav class="flex overflow-hidden rounded-md text-xs ring-1 ring-ink-200" aria-label="Chart period">
                                @foreach (SystemOverview::PERIODS as $key => $label)
                                    <a href="{{ route('admin.dashboard', $key === SystemOverview::TODAY ? [] : ['period' => $key]) }}" class="px-3 py-1 {{ $overview->period === $key ? 'bg-ink-100 font-semibold text-ink-900' : 'text-ink-500 hover:bg-ink-50' }}" @if ($overview->period === $key) aria-current="page" @endif>{{ $label }}</a>
                                @endforeach
                            </nav>
                        </div>
                        <div class="px-4 py-3">
                            @include('admin._dashboard-chart', ['overview' => $overview])
                        </div>
                    </section>

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                        <div class="space-y-5">
                            <section class="{{ $panel }}">
                                <h2 class="{{ $panelTitle }}">Billing <span class="font-normal text-ink-400">· income in {{ SystemOverview::currency() }}</span></h2>
                                <div class="grid grid-cols-2 divide-x divide-ink-100">
                                    @foreach ([['Today', $incomeToday], ['This Month', $incomeThisMonth], ['This Year', $incomeThisYear], ['All Time', $incomeAllTime]] as $i => [$label, $amount])
                                        <div class="px-4 py-3 {{ $i >= 2 ? 'border-t border-ink-100' : '' }}">
                                            <div class="font-display text-xl font-bold {{ $amount ? 'text-brand-700' : 'text-ink-400' }}">{{ Money::format($amount, SystemOverview::currency()) }}</div>
                                            <div class="text-xs text-ink-500">{{ $label }}</div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">
                                    <a href="{{ route('admin.invoices.index', ['status' => 'unpaid']) }}" class="text-brand-700">{{ $unpaidCount }} unpaid {{ Str::plural('invoice', $unpaidCount) }}</a> totalling {{ Money::format($unpaidTotal) }}
                                </div>
                            </section>

                            <section class="{{ $panel }}">
                                <h2 class="{{ $panelTitle }}">Payments to review</h2>
                                <div class="px-4 py-3 text-sm">
                                    <p><strong>{{ $paymentsToReviewCount }}</strong> {{ Str::plural('payment', $paymentsToReviewCount) }} waiting for review</p>
                                    @if ($paymentsToReview->isNotEmpty())
                                        <ul class="mt-2 divide-y divide-ink-100 text-xs">
                                            @foreach ($paymentsToReview as $payment)
                                                <li class="flex justify-between gap-2 py-1.5">
                                                    <span>
                                                        @if ($payment->invoice)
                                                            <a href="{{ route('admin.invoices.show', $payment->invoice) }}" class="text-brand-700">Invoice #{{ $payment->invoice->number }}</a>
                                                        @else
                                                            {{ $payment->methodLabel() }}
                                                        @endif
                                                        · {{ $payment->client?->fullName() }}
                                                    </span>
                                                    <span class="whitespace-nowrap text-ink-500">{{ Money::format($payment->amount, $payment->currency) }} · {{ $payment->dispute_status ? 'dispute: '.str_replace('_', ' ', $payment->dispute_status) : 'clearing' }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="mt-1 text-xs text-ink-500">Payments still clearing at the gateway and open disputes show here.</p>
                                    @endif
                                </div>
                            </section>
                        </div>

                        <section class="{{ $panel }}">
                            <h2 class="{{ $panelTitle }}">Client Activity</h2>
                            <div class="grid grid-cols-2 gap-3 px-4 py-3">
                                <a href="{{ route('admin.clients.index', ['status' => 'active']) }}" class="block">
                                    <div class="text-xs text-ink-500">Active Clients</div>
                                    <div class="font-display text-xl font-bold text-amber-600">{{ number_format($activeClients) }} <span class="text-sm font-normal text-ink-500">Active</span></div>
                                </a>
                                <div>
                                    <div class="text-xs text-ink-500">Clients Signed In</div>
                                    <div class="font-display text-xl font-bold text-brand-600">{{ number_format($clientsSignedIn) }} <span class="text-sm font-normal text-ink-500">Last Hour</span></div>
                                </div>
                            </div>
                            <div class="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">{{ number_format($activeServices) }} active {{ Str::plural('service', $activeServices) }}@if ($pendingServices), {{ $pendingServices }} pending @endif</div>
                            <h3 class="border-t border-ink-100 px-4 pt-2 text-xs font-semibold uppercase tracking-wide text-ink-400">Recent client logins</h3>
                            @if ($recentClientLogins->isEmpty())
                                <p class="px-4 py-3 text-sm text-ink-500">No client has signed in to the portal yet.</p>
                            @else
                                <ul class="divide-y divide-ink-100 px-4 pb-2 text-sm">
                                    @foreach ($recentClientLogins as $login)
                                        <li class="flex items-center justify-between gap-2 py-2">
                                            @if ($login->client)
                                                <a href="{{ route('admin.clients.show', $login->client) }}" class="truncate text-brand-700">{{ $login->who() }}</a>
                                            @else
                                                <span class="truncate">{{ $login->who() }}</span>
                                            @endif
                                            <span class="whitespace-nowrap text-xs text-ink-500" title="{{ $login->created_at?->format('M j, Y g:ia') }}">{{ $login->created_at?->diffForHumans() }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    </div>
                </div>

                <div class="space-y-5">
                    <section class="{{ $panel }}">
                        <h2 class="{{ $panelTitle }}">Automation Overview</h2>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-3 px-4 py-3">
                            @foreach ($automation as $metric)
                                @php $peak = max(1, ...$metric['days']); @endphp
                                <div class="text-center {{ $metric['color'] }}">
                                    <svg viewBox="0 0 96 24" class="mx-auto h-6 w-24" aria-hidden="true" preserveAspectRatio="none">
                                        <polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" opacity="0.6"
                                            points="{{ collect($metric['days'])->map(fn ($n, $i) => ($i * 16).','.round(22 - $n / $peak * 20, 1))->implode(' ') }}" />
                                    </svg>
                                    <div class="text-xs text-ink-500">{{ $metric['label'] }}</div>
                                    <div class="font-display text-xl font-bold">{{ $metric['today'] }}</div>
                                </div>
                            @endforeach
                        </div>
                        <p class="border-t border-ink-100 px-4 py-2 text-xs text-ink-500">
                            Counts for today, with the last 7 days drawn above each.
                            Last automation run:
                            @if ($lastAutomationRun)
                                <strong class="{{ $lastAutomationRun->status === App\Models\ScheduledTaskRun::FAILED ? 'text-red-700' : 'text-ink-700' }}">{{ $lastAutomationRun->started_at->isToday() ? 'Today' : $lastAutomationRun->started_at->format('M j') }} at {{ $lastAutomationRun->started_at->format('g:i A') }}</strong>@if ($lastAutomationRun->status === App\Models\ScheduledTaskRun::FAILED) (failed)@endif
                            @else
                                <strong class="text-ink-700">not recorded yet</strong>
                            @endif
                        </p>
                    </section>

                    <section class="{{ $panel }}">
                        <h2 class="{{ $panelTitle }}">Activity</h2>
                        @if ($activity === null)
                            <p class="px-4 py-3 text-sm text-ink-500">Your role can't see the activity log.</p>
                        @elseif ($activity->isEmpty())
                            <p class="px-4 py-3 text-sm text-ink-500">Nothing logged yet.</p>
                        @else
                            <ul class="max-h-96 divide-y divide-ink-100 overflow-y-auto px-4 text-sm">
                                @foreach ($activity as $entry)
                                    <li class="py-2">
                                        <div class="flex justify-between gap-2">
                                            <span class="font-semibold text-ink-800">{{ $entry->actor_name ?? 'System' }}</span>
                                            <span class="whitespace-nowrap text-xs text-ink-500" title="{{ $entry->created_at?->format('M j, Y g:ia') }}">{{ $entry->created_at?->diffForHumans() }}</span>
                                        </div>
                                        <div class="text-ink-600">{{ $entry->description }}</div>
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ route('admin.activity.index') }}" class="block border-t border-ink-100 px-4 py-2 text-xs text-brand-700">View the activity log</a>
                        @endif
                    </section>
                </div>
            </div>

            {{-- The work queues, as WHMCS's extra widgets. --}}
            @if ($pendingOrders->isNotEmpty())
                <h2 class="mb-3 mt-10 text-lg font-semibold">Pending orders</h2>
                @include('admin.orders._table', ['orders' => $pendingOrders])
                <a href="{{ route('admin.orders.index') }}" class="mt-2 inline-block text-sm text-brand-600">View all pending orders</a>
            @endif

            @if ($waitingTickets->isNotEmpty())
                <h2 class="mb-3 mt-10 text-lg font-semibold">Tickets waiting on us</h2>
                @include('admin.tickets._table', ['tickets' => $waitingTickets])
                <a href="{{ route('admin.tickets.index') }}" class="mt-2 inline-block text-sm text-brand-600">View all tickets</a>
            @endif

            @if ($cancellations->isNotEmpty())
                <h2 class="mb-3 mt-10 text-lg font-semibold">Upcoming cancellations</h2>
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Service</th><th class="px-4 py-2">Client</th><th class="px-4 py-2">Cancels on</th><th class="px-4 py-2">Reason</th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($cancellations as $cancellation)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.services.show', $cancellation->service) }}" class="text-brand-600">{{ $cancellation->service->description() }}</a></td>
                                    <td class="px-4 py-2"><a href="{{ route('admin.clients.show', $cancellation->service->client) }}" class="text-brand-600">{{ $cancellation->service->client->fullName() }}</a></td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ $cancellation->cancel_on->format('M j, Y') }}</td>
                                    <td class="px-4 py-2 text-ink-600">{{ Str::limit($cancellation->reason ?? '', 80) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($openTasks->isNotEmpty())
                <h2 class="mb-3 mt-10 text-lg font-semibold">Open tasks</h2>
                @include('admin.tasks._table', ['tasks' => $openTasks])
                <a href="{{ route('admin.tasks.index') }}" class="mt-2 inline-block text-sm text-brand-600">View all tasks</a>
            @endif

            @if ($todos->isNotEmpty())
                <h2 class="mb-3 mt-10 text-lg font-semibold">Your to-do list</h2>
                @include('admin.todos._table', ['todos' => $todos])
                <a href="{{ route('admin.todos.index') }}" class="mt-2 inline-block text-sm text-brand-600">View your to-do list</a>
            @endif

            <h2 class="mb-3 mt-10 text-lg font-semibold">Overdue invoices</h2>
            @if ($overdue->isEmpty())
                <p class="text-sm text-ink-500">Nothing overdue.</p>
            @else
                @include('admin.invoices._table', ['invoices' => $overdue])
                <a href="{{ route('admin.invoices.index', ['status' => 'overdue']) }}" class="mt-2 inline-block text-sm text-brand-600">View all overdue</a>
            @endif
        </div>
    </div>
</x-admin-layout>
