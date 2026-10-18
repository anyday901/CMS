<x-portal-layout :title="__('Invoices')">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Invoices') }}</h1>
        @if ($savedCards)
            <a href="{{ route('portal.payment-methods.index') }}" class="ml-auto rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Payment methods') }}</a>
        @endif
    </div>
    @if ($invoices->isEmpty())
        <p class="text-sm text-ink-500">{{ __('You have no invoices yet.') }}</p>
    @else
        @include('portal.invoices._table')
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
    @if ($creditNotes->isNotEmpty())
        <h2 class="mb-3 mt-8 text-lg font-semibold">{{ __('Credit notes') }}</h2>
        @include('portal.credit-notes._table')
    @endif
</x-portal-layout>
