@php use App\Support\Money; @endphp
<x-admin-layout title="Dashboard">
    <h1 class="mb-6 text-2xl font-semibold">Dashboard</h1>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach ([
            ['Income this month', Money::format($incomeThisMonth)],
            ['Unpaid invoices', $unpaidCount.' · '.Money::format($unpaidTotal)],
            ['Active clients', number_format($activeClients)],
            ['Active services', number_format($activeServices).($pendingServices ? " · {$pendingServices} pending" : '')],
        ] as $i => [$label, $value])
            <div class="rounded-lg p-4 {{ $i === 0 ? 'bg-brand-600 text-white' : 'border border-ink-200 bg-white' }}">
                <div class="text-sm {{ $i === 0 ? 'text-brand-100' : 'text-ink-500' }}">{{ $label }}</div>
                <div class="mt-1 font-display text-xl font-bold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @if ($pendingOrders->isNotEmpty())
        <h2 class="mb-3 mt-10 text-lg font-semibold">Pending orders</h2>
        @include('admin.orders._table', ['orders' => $pendingOrders])
        <a href="{{ route('admin.orders.index') }}" class="mt-2 inline-block text-sm text-brand-600">View all pending orders</a>
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

    @if ($waitingTickets->isNotEmpty())
        <h2 class="mb-3 mt-10 text-lg font-semibold">Tickets waiting on us</h2>
        @include('admin.tickets._table', ['tickets' => $waitingTickets])
        <a href="{{ route('admin.tickets.index') }}" class="mt-2 inline-block text-sm text-brand-600">View all tickets</a>
    @endif

    @if ($openTasks->isNotEmpty())
        <h2 class="mb-3 mt-10 text-lg font-semibold">Open tasks</h2>
        @include('admin.tasks._table', ['tasks' => $openTasks])
        <a href="{{ route('admin.tasks.index') }}" class="mt-2 inline-block text-sm text-brand-600">View all tasks</a>
    @endif

    <h2 class="mb-3 mt-10 text-lg font-semibold">Overdue invoices</h2>
    @if ($overdue->isEmpty())
        <p class="text-sm text-ink-500">Nothing overdue.</p>
    @else
        @include('admin.invoices._table', ['invoices' => $overdue])
        <a href="{{ route('admin.invoices.index', ['status' => 'overdue']) }}" class="mt-2 inline-block text-sm text-brand-600">View all overdue</a>
    @endif
</x-admin-layout>
