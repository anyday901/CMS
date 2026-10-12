<x-admin-layout title="Orders">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Orders{{ $client ? ' for '.$client->fullName() : '' }}</h1>
        <form class="ml-auto">
            @if ($client)<input type="hidden" name="client" value="{{ $client->id }}">@endif
            <label for="order-search" class="sr-only">Search orders</label>
            <input id="order-search" type="search" name="q" value="{{ $search }}" placeholder="Order number, client name or email" class="w-72 rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300">
        </form>
    </div>
    <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
        @foreach (['pending' => 'Pending', 'active' => 'Accepted', 'cancelled' => 'Cancelled', 'fraud' => 'Fraud', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.orders.index', array_filter(['status' => $value, 'q' => $search, 'client' => $client?->id])) }}"
               class="rounded-full px-3 py-1 {{ ($status?->value ?? 'all') === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
        @if ($client || $search !== '')
            <a href="{{ route('admin.orders.index') }}" class="ml-2 text-brand-600">Clear</a>
        @endif
    </div>
    @if ($orders->isEmpty())
        <p class="text-sm text-ink-500">{{ $status?->value === 'pending' && ! $client && $search === '' ? 'No orders waiting for review.' : 'No orders found.' }}</p>
    @else
        @include('admin.orders._table')
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</x-admin-layout>
