<x-admin-layout title="Invoices">
    <h1 class="mb-4 text-2xl font-semibold">Invoices</h1>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['' => 'All', 'draft' => 'Drafts', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $value => $label)
            <a href="{{ route('admin.invoices.index', array_filter(['status' => $value])) }}"
               class="rounded-full px-3 py-1 {{ ($status ?? '') === $value ? 'bg-brand-600 text-white' : 'bg-white ring-1 ring-ink-300' }}">{{ $label }}</a>
        @endforeach
    </div>
    @if ($invoices->isEmpty())
        <p class="text-sm text-ink-500">No invoices.</p>
    @else
        @include('admin.invoices._table')
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</x-admin-layout>
