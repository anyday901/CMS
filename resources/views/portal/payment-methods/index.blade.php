<x-portal-layout :title="__('Payment methods')">
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">{{ __('Payment methods') }}</h1>
        <a href="{{ route('portal.invoices.index') }}" class="ml-auto text-sm text-brand-600">{{ __('Invoices') }}</a>
    </div>

    <div class="mb-6 rounded-lg border border-ink-200 bg-white text-sm">
        @forelse ($methods as $method)
            <div class="flex flex-wrap items-center gap-3 border-b border-ink-100 px-4 py-3 last:border-b-0">
                <div class="grow">
                    <span class="font-medium">{{ $method->label() }}</span>
                    @if ($method->expiry())
                        <span class="{{ $method->isExpired() ? 'text-red-700' : 'text-ink-500' }}">· {{ $method->isExpired() ? __('expired :date', ['date' => $method->expiry()]) : __('expires :date', ['date' => $method->expiry()]) }}</span>
                    @endif
                    @if ($method->is_default)
                        <span class="ml-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-semibold text-brand-700">{{ __('Default') }}</span>
                    @endif
                </div>
                @unless ($method->is_default)
                    <form method="POST" action="{{ route('portal.payment-methods.default', $method->id) }}">
                        @csrf
                        <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Make default') }}</button>
                    </form>
                @endunless
                <form method="POST" action="{{ route('portal.payment-methods.destroy', $method->id) }}" onsubmit="return confirm(@js(__('Remove this card?')))">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-md px-3 py-1.5 text-red-700 ring-1 ring-ink-300 hover:bg-red-50">{{ __('Remove') }}</button>
                </form>
            </div>
        @empty
            <p class="px-4 py-3 text-ink-500">{{ __('You have no saved cards.') }}</p>
        @endforelse
    </div>

    @if ($methods->isNotEmpty())
        <form method="POST" action="{{ route('portal.payment-methods.auto-charge') }}" class="mb-6 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            @method('PUT')
            <h2 class="mb-2 font-semibold">{{ __('Automatic payments') }}</h2>
            @if ($client->auto_charge)
                <p class="mb-3 text-ink-600">{{ __('Invoices are charged to your default card when they are due. If a payment fails, we email you and try again a few days later.') }}</p>
                <input type="hidden" name="auto_charge" value="0">
                <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Turn automatic payments off') }}</button>
            @else
                <p class="mb-3 text-ink-600">{{ __('Automatic payments are off. Pay each invoice from its invoice page.') }}</p>
                <input type="hidden" name="auto_charge" value="1">
                <button class="rounded-md px-3 py-1.5 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Turn automatic payments on') }}</button>
            @endif
        </form>
    @endif

    @foreach ($gateways as $gateway)
        <div class="max-w-md rounded-lg border border-ink-200 bg-white p-4 text-sm">
            <h2 class="mb-3 font-semibold">{{ __('Add a card') }}</h2>
            @include($gateway->addMethodView(), $gateway->addMethodViewData($client))
            <p class="mt-3 text-ink-500">{{ __('Your card details go straight to our payment processor; we never see or store the full number.') }}</p>
        </div>
    @endforeach
</x-portal-layout>
