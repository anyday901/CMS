<x-portal-layout title="Invoices">
    <h1 class="mb-6 text-2xl font-semibold">Invoices</h1>
    @if ($invoices->isEmpty())
        <p class="text-sm text-ink-500">You have no invoices yet.</p>
    @else
        @include('portal.invoices._table')
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</x-portal-layout>
