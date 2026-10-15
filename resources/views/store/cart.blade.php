<x-portal-layout :title="__('Cart')" :store="true">
    <h1 class="mb-6 text-2xl font-semibold">{{ __('Your cart') }}</h1>

    @foreach ($quote->errors as $key => $error)
        <div class="mb-3 flex flex-wrap items-center gap-3 rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">
            <span>{{ $error }}</span>
            <form method="POST" action="{{ route('cart.destroy', $key) }}" class="ml-auto">@csrf @method('DELETE')
                <button class="rounded-full bg-white px-3 py-1 font-medium ring-1 ring-orange-300 hover:bg-orange-100">{{ __('Remove it') }}</button>
            </form>
        </div>
    @endforeach

    @if ($quote->rows === [])
        <p class="text-sm text-ink-500">{{ __('Your cart is empty.') }} <a href="{{ route('store.index') }}" class="text-brand-600">{{ __('Browse products') }}</a></p>
    @else
        @include('store._summary', ['removable' => true])

        <div class="mt-6 flex flex-wrap items-start gap-6">
            <div class="text-sm">
                @if ($quote->promotion)
                    <form method="POST" action="{{ route('cart.promotion.destroy') }}" class="flex items-center gap-3">@csrf @method('DELETE')
                        <span>{!! __('Code :code: :summary', ['code' => '<strong>'.e($quote->promotion->code).'</strong>', 'summary' => e($quote->promotion->summary())]) !!}</span>
                        <button class="text-red-600 hover:underline">{{ __('Remove') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('cart.promotion') }}" class="flex items-end gap-2">@csrf
                        <label class="block">{{ __('Promotion code') }}
                            <input name="code" value="{{ old('code') }}" maxlength="50" class="mt-1 block w-48 rounded-md px-3 py-2 uppercase ring-1 ring-ink-300">
                        </label>
                        <button class="rounded-full px-4 py-2 ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Apply') }}</button>
                    </form>
                @endif
            </div>
            <div class="ml-auto flex items-center gap-3">
                <a href="{{ route('store.index') }}" class="rounded-full px-4 py-2 text-sm ring-1 ring-ink-300 hover:bg-ink-100">{{ __('Keep shopping') }}</a>
                @if ($quote->errors === [])
                    <a href="{{ route('checkout.show') }}" class="rounded-full bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-500">{{ __('Check out') }}</a>
                @endif
            </div>
        </div>
    @endif
</x-portal-layout>
