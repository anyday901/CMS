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
