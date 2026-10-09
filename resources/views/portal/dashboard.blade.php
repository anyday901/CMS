@php use App\Support\Money; @endphp
<x-portal-layout title="Home">
    <h1 class="mb-6 text-2xl font-semibold">Welcome, {{ $client->first_name }}</h1>

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
</x-portal-layout>
