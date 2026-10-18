@php use App\Support\Money; @endphp
<x-portal-layout :title="__('Add funds')">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Add funds') }}</h1>
        <a href="{{ route('portal.invoices.index') }}" class="ml-auto text-sm text-brand-600">{{ __('Invoices') }}</a>
    </div>

    <div class="max-w-lg rounded-lg border border-ink-200 bg-white p-4 text-sm">
        <p class="mb-3 text-ink-600">{{ __('Pay into your account credit now, and it is used on your invoices later. Your credit balance is :amount.', ['amount' => Money::format($client->credit_balance, $client->currency)]) }}</p>
        @if ($available !== null && $available < $limits['minimum'])
            <p class="text-ink-600">{{ __('Your account credit is at its limit of :amount, so no more can be added.', ['amount' => Money::format((int) $limits['maximum_balance'], $client->currency)]) }}</p>
        @else
            <form method="POST" action="{{ route('portal.add-funds.store') }}" class="space-y-3">
                @csrf
                <label class="block">{{ __('Amount (:currency)', ['currency' => $client->currency]) }}
                    <input type="text" name="amount" inputmode="decimal" required maxlength="20" value="{{ old('amount', Money::toInput($limits['minimum'])) }}" class="mt-1 block w-40 rounded-md px-3 py-2 ring-1 ring-ink-300">
                </label>
                <p class="text-ink-500">
                    @if ($available !== null)
                        {{ __('Between :minimum and :maximum.', ['minimum' => Money::format($limits['minimum'], $client->currency), 'maximum' => Money::format($available, $client->currency)]) }}
                    @else
                        {{ __('At least :minimum.', ['minimum' => Money::format($limits['minimum'], $client->currency)]) }}
                    @endif
                </p>
                @error('amount')<p class="text-red-700">{{ $message }}</p>@enderror
                <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">{{ __('Continue to payment') }}</button>
            </form>
        @endif
    </div>
</x-portal-layout>
