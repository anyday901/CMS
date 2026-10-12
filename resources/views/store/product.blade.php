@php use App\Support\Money; @endphp
<x-portal-layout :title="$product->name" :store="true">
    <p class="mb-2 text-sm">
        <a href="{{ route('store.index') }}" class="text-brand-600">All products</a>
        @if ($product->group) <span class="text-ink-400">/</span> <a href="{{ route('store.group', $product->group) }}" class="text-brand-600">{{ $product->group->name }}</a>@endif
    </p>
    <h1 class="mb-2 text-2xl font-semibold">{{ $product->name }}</h1>
    @if ($product->description)<p class="mb-6 max-w-2xl text-sm text-ink-600">{!! nl2br(e($product->description)) !!}</p>@endif

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

        <label class="block">Domain or hostname (optional)
            <input name="label" value="{{ old('label') }}" maxlength="255" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        @include('custom-fields._inputs', ['fields' => $fields, 'values' => []])

        <button class="rounded-full bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-500">Add to cart</button>
    </form>
</x-portal-layout>
