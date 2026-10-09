@php use App\Support\Money; @endphp
<x-admin-layout title="Dashboard">
    <h1 class="mb-6 text-2xl font-semibold">Dashboard</h1>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach ([
            ['Income this month', Money::format($incomeThisMonth)],
            ['Unpaid invoices', $unpaidCount.' · '.Money::format($unpaidTotal)],
            ['Active clients', number_format($activeClients)],
            ['Active services', number_format($activeServices).($pendingServices ? " · {$pendingServices} pending" : '')],
        ] as [$label, $value])
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <h2 class="mb-3 mt-10 text-lg font-semibold">Overdue invoices</h2>
    @if ($overdue->isEmpty())
        <p class="text-sm text-gray-500">Nothing overdue.</p>
    @else
        @include('admin.invoices._table', ['invoices' => $overdue])
        <a href="{{ route('admin.invoices.index', ['status' => 'overdue']) }}" class="mt-2 inline-block text-sm text-indigo-600">View all overdue</a>
    @endif
</x-admin-layout>
