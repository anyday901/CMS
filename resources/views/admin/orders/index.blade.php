<x-admin-layout title="Orders">
    <h1 class="mb-4 text-2xl font-semibold">Orders</h1>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['pending' => 'Pending', 'active' => 'Accepted', 'cancelled' => 'Cancelled', 'fraud' => 'Fraud', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.orders.index', ['status' => $value]) }}"
               class="rounded-full px-3 py-1 {{ ($status?->value ?? 'all') === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
    </div>
    @if ($orders->isEmpty())
        <p class="text-sm text-ink-500">{{ $status?->value === 'pending' ? 'No orders waiting for review.' : 'No orders.' }}</p>
    @else
        @include('admin.orders._table')
        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</x-admin-layout>
