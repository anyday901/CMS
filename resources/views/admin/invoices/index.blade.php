<x-admin-layout title="Invoices">
    <h1 class="mb-4 text-2xl font-semibold">Invoices</h1>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['' => 'All', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $value => $label)
            <a href="{{ route('admin.invoices.index', array_filter(['status' => $value])) }}"
               class="rounded-full px-3 py-1 {{ ($status ?? '') === $value ? 'bg-indigo-600 text-white' : 'bg-white ring-1 ring-gray-300' }}">{{ $label }}</a>
        @endforeach
    </div>
    @if ($invoices->isEmpty())
        <p class="text-sm text-gray-500">No invoices.</p>
    @else
        @include('admin.invoices._table')
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</x-admin-layout>
