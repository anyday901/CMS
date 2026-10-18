<x-portal-layout :title="__('Invoices')">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Invoices') }}</h1>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="{{ route('portal.statement.show') }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Account statement') }}</a>
            @if ($addFunds)
                <a href="{{ route('portal.add-funds.create') }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Add funds') }}</a>
            @endif
            @if ($savedCards)
                <a href="{{ route('portal.payment-methods.index') }}" class="rounded-md px-3 py-1.5 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Payment methods') }}</a>
            @endif
        </div>
    </div>
    @if ($invoices->isEmpty())
        <p class="text-sm text-ink-500">{{ __('You have no invoices yet.') }}</p>
    @else
        @if ($massPayable)
            {{-- The table's checkboxes belong to this form through their form attribute. --}}
            <form id="mass-pay" method="POST" action="{{ route('portal.invoices.mass-pay') }}" class="mb-3 flex flex-wrap items-center gap-3 text-sm">
                @csrf
                <span class="text-ink-600">{{ __('Tick the unpaid invoices you want to pay together.') }}</span>
                <button class="rounded-md px-3 py-1.5 font-medium ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Pay selected invoices') }}</button>
            </form>
            @error('invoices')<p class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
        @endif
        @include('portal.invoices._table')
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
    @if ($creditNotes->isNotEmpty())
        <h2 class="mb-3 mt-8 text-lg font-semibold">{{ __('Credit notes') }}</h2>
        @include('portal.credit-notes._table')
    @endif
</x-portal-layout>
