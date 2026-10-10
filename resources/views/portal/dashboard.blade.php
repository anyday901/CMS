@php use App\Support\Money; @endphp
<x-portal-layout title="Home">
    <h1 class="mb-6 text-2xl font-semibold">Welcome, {{ $client->first_name }}</h1>

    @if ($client->credit_balance > 0)
        <p class="mb-4 text-sm text-gray-700">You have <strong>{{ Money::format($client->credit_balance, $client->currency) }}</strong> of account credit, which you can use on any unpaid invoice.</p>
    @endif

    @if ($unpaid->isNotEmpty())
        <div class="mb-8 rounded-lg border border-yellow-200 bg-yellow-50 p-4 text-sm">
            You have {{ $unpaid->count() }} unpaid {{ Str::plural('invoice', $unpaid->count()) }} totaling
            <strong>{{ Money::format($unpaid->sum(fn ($i) => $i->balance()), $client->currency) }}</strong>.
            <a href="{{ route('portal.invoices.show', $unpaid->first()) }}" class="font-medium text-indigo-600">View the next one due</a>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Your services</h2>
    @if ($services->isEmpty())
        <p class="mb-8 text-sm text-gray-500">You have no active services.</p>
    @else
        <div class="mb-8">@include('portal.services._table')</div>
    @endif

    <h2 class="mb-3 text-lg font-semibold">Unpaid invoices</h2>
    @if ($unpaid->isEmpty())
        <p class="text-sm text-gray-500">You're all paid up.</p>
    @else
        @include('portal.invoices._table', ['invoices' => $unpaid])
    @endif

    @if ($credit->isNotEmpty())
        <h2 class="mt-8 mb-3 text-lg font-semibold">Account credit</h2>
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">What</th><th class="px-4 py-2 text-right">Change</th><th class="px-4 py-2 text-right">Balance</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($credit as $entry)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2">{{ $entry->created_at->format('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $entry->description }}</td>
                            <td class="px-4 py-2 text-right">{{ $entry->amount > 0 ? '+' : '' }}{{ Money::format($entry->amount, $client->currency) }}</td>
                            <td class="px-4 py-2 text-right">{{ Money::format($entry->balance_after, $client->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-portal-layout>
