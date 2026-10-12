{{-- $product (with prices in $currency), $currency --}}
@php
    use App\Support\Money;
    $from = $product->pricesIn($currency)->first();
@endphp
<div class="flex flex-col rounded-lg border border-ink-200 bg-white p-5">
    <h3 class="font-display text-lg font-bold">{{ $product->name }}</h3>
    @if ($product->description)
        <p class="mt-2 text-sm text-ink-600">{!! nl2br(e(Str::limit($product->description, 300))) !!}</p>
    @endif
    <div class="mt-auto pt-4">
        @if ($from)
            <p class="text-sm text-ink-500">{{ $product->pricesIn($currency)->count() > 1 ? 'From' : 'Price' }}</p>
            <p class="font-display text-2xl font-bold">{{ Money::format($from->price, $currency) }} <span class="text-sm font-normal text-ink-500">{{ strtolower($from->billing_cycle->label()) }}</span></p>
            @if ($from->setup_fee)<p class="text-xs text-ink-500">+ {{ Money::format($from->setup_fee, $currency) }} setup</p>@endif
        @endif
        @if ($product->trial_days)<p class="text-sm font-medium text-brand-700">{{ $product->trial_days }}-day free trial</p>@endif
        @if ($product->outOfStock())
            <p class="mt-3 inline-block rounded-full bg-ink-100 px-4 py-2 text-sm font-medium text-ink-600">Out of stock</p>
        @else
            <a href="{{ route('store.product', $product) }}" class="mt-3 inline-block rounded-full bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">Order now</a>
        @endif
    </div>
</div>
