@php use App\Support\Money; @endphp
<x-admin-layout title="Add service">
    <h1 class="mb-6 text-2xl font-semibold">Add service for {{ $client->fullName() }}</h1>
    @if ($products->isEmpty())
        <p class="text-sm text-ink-500">There are no active products. <a href="{{ route('admin.products.create') }}" class="text-brand-600">Add a product</a> first.</p>
    @else
        <form method="POST" action="{{ route('admin.services.store', $client) }}" class="max-w-xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
            @csrf
            <label class="block">Product and billing cycle
                <select name="product_cycle" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300"
                        onchange="const [p, c] = this.value.split('|'); this.form.product_id.value = p; this.form.billing_cycle.value = c;">
                    <option value="">Choose…</option>
                    @foreach ($products as $product)
                        <optgroup label="{{ $product->name }}">
                            @foreach ($product->prices->where('currency', $client->currency) as $price)
                                <option value="{{ $product->id }}|{{ $price->billing_cycle->value }}">
                                    {{ $product->name }}: {{ Money::format($price->price, $price->currency) }} {{ strtolower($price->billing_cycle->label()) }}{{ $price->setup_fee ? ' + '.Money::format($price->setup_fee, $price->currency).' setup' : '' }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>
            <input type="hidden" name="product_id" value="{{ old('product_id') }}">
            <input type="hidden" name="billing_cycle" value="{{ old('billing_cycle') }}">
            @foreach ($products as $product)
                @php $productFields = $serviceFields->where('product_id', $product->id); @endphp
                @if ($product->options->isNotEmpty() || $productFields->isNotEmpty())
                    <fieldset data-product="{{ $product->id }}" class="space-y-3" hidden>
                        <legend class="mb-1 font-semibold">{{ $product->name }} options</legend>
                        @include('services._options', ['options' => $product->options, 'values' => []])
                        @include('custom-fields._inputs', ['fields' => $productFields, 'values' => []])
                    </fieldset>
                @endif
            @endforeach
            @include('custom-fields._inputs', ['fields' => $serviceFields->whereNull('product_id'), 'values' => []])
            <label class="block">Label (optional, e.g. a domain or hostname)
                <input name="label" value="{{ old('label') }}" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Start date
                <input type="date" name="start_date" value="{{ old('start_date', today()->toDateString()) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <input type="hidden" name="invoice" value="0">
            <label class="flex items-start gap-2"><input type="checkbox" name="invoice" value="1" checked class="mt-0.5">
                <span>Create the first invoice now. The service stays pending until it's paid. Untick this for an existing service that's already been paid for.</span></label>
            <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add service</button>
        </form>
        <script>
            // Show only the chosen product's options and fields; hidden ones are disabled so they aren't sent or required.
            (() => {
                const form = document.querySelector('form[action="{{ route('admin.services.store', $client) }}"]');
                const sync = () => form.querySelectorAll('[data-product]').forEach(set => {
                    set.hidden = set.dataset.product !== form.product_id.value;
                    set.disabled = set.hidden;
                });
                form.product_cycle.addEventListener('change', sync);
                if (form.product_id.value) {
                    form.product_cycle.value = form.product_id.value + '|' + form.billing_cycle.value;
                }
                sync();
            })();
        </script>
    @endif
</x-admin-layout>
