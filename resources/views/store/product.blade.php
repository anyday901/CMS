@php use App\Support\Money; @endphp
<x-portal-layout :title="$product->name" :store="true">
    <p class="mb-2 text-sm">
        <a href="{{ route('store.index') }}" class="text-brand-600">All products</a>
        @if ($product->group) <span class="text-ink-400">/</span> <a href="{{ route('store.group', $product->group) }}" class="text-brand-600">{{ $product->group->name }}</a>@endif
    </p>
    <h1 class="mb-2 text-2xl font-semibold">{{ $product->name }}</h1>
    @if ($product->description)<p class="mb-6 max-w-2xl text-sm text-ink-600">{!! nl2br(e($product->description)) !!}</p>@endif
    @if ($product->trial_days || $product->prorata_day)
        <ul class="mb-6 max-w-2xl space-y-1 text-sm text-ink-600">
            @if ($product->trial_days)<li><span class="font-medium text-brand-700">Free for {{ $product->trial_days }} days{{ $prices->contains(fn ($price) => ! $price->billing_cycle->isRecurring()) ? ' on recurring billing cycles' : '' }}.</span> Your first invoice comes when the trial ends{{ $prices->contains(fn ($price) => $price->setup_fee > 0) ? '; any setup fee is due now' : '' }}. One trial per customer.</li>@endif
            @if ($product->prorata_day)<li>Billed on day {{ $product->prorata_day }} of the month. Your first payment covers the days until then.</li>@endif
        </ul>
    @endif

    @if ($product->outOfStock())
        <p class="max-w-xl rounded-lg border border-ink-200 bg-white p-6 text-sm">{{ $product->name }} is out of stock right now. Please check back later.</p>
    @else

    <form method="POST" action="{{ route('cart.store', $product) }}" class="max-w-xl space-y-5 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        <fieldset>
            <legend class="mb-2 font-semibold">Billing cycle</legend>
            <div class="space-y-2">
                @foreach ($prices as $price)
                    <label class="flex items-center gap-3 rounded-md px-3 py-2 ring-1 ring-ink-200 has-[:checked]:bg-brand-50 has-[:checked]:ring-brand-500">
                        <input type="radio" name="billing_cycle" value="{{ $price->billing_cycle->value }}" required @checked(old('billing_cycle', $prices->first()->billing_cycle->value) === $price->billing_cycle->value)>
                        <span class="font-medium">{{ $price->billing_cycle->label() }}</span>
                        <span class="ml-auto">{{ Money::format($price->price, $currency) }}@if ($price->setup_fee) <span class="text-ink-500">+ {{ Money::format($price->setup_fee, $currency) }} setup</span>@endif</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if ($product->options->isNotEmpty())
            <fieldset class="space-y-3">
                <legend class="mb-1 font-semibold">Options</legend>
                @include('services._options', ['options' => $product->options, 'values' => [], 'cycleHint' => $prices->count() === 1 ? $prices->first()->billing_cycle : null])
                @if ($prices->count() > 1)<p class="text-ink-500">Option prices depend on the billing cycle. Your cart shows the total.</p>@endif
            </fieldset>
        @endif

        @if ($addons->isNotEmpty())
            <fieldset class="space-y-2">
                <legend class="mb-1 font-semibold">Add-ons</legend>
                @foreach ($addons as $addon)
                    <label class="flex items-start gap-2">
                        <input type="checkbox" name="addons[]" value="{{ $addon->id }}" class="mt-0.5" @checked(in_array($addon->id, array_map('intval', (array) old('addons', []))))>
                        <span><span class="font-medium">{{ $addon->name }}</span>
                            <span class="text-ink-500">{{ $addon->pricesIn($currency)->map(fn ($p) => Money::format($p->price, $currency).' '.strtolower($p->billing_cycle->label()))->join(', ') }}</span>
                            @if ($addon->description)<span class="block text-ink-500">{{ $addon->description }}</span>@endif
                        </span>
                    </label>
                @endforeach
                <p class="text-ink-500">Add-ons are billed on the same cycle as {{ $product->name }} where they can be.</p>
            </fieldset>
        @endif

        @if ($needsDomain)
            <label class="block">Domain
                <input name="label" value="{{ old('label') }}" maxlength="255" required placeholder="example.com" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                <span class="text-ink-500">The domain this service is set up for. You can point it here once the service is ready.</span>
            </label>
        @else
            <label class="block">Domain or hostname (optional)
                <input name="label" value="{{ old('label') }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
        @endif
        @include('custom-fields._inputs', ['fields' => $fields, 'values' => []])

        <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">Add to cart</button>
    </form>
    @endif
</x-portal-layout>
