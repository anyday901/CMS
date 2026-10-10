@php use App\Support\Money; @endphp
<x-admin-layout :title="$product->exists ? 'Edit product' : 'Add product'">
    <h1 class="mb-6 text-2xl font-semibold">{{ $product->exists ? 'Edit '.$product->name : 'Add product' }}</h1>
    <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
          class="max-w-3xl space-y-4 rounded-lg border border-ink-200 bg-white p-6 text-sm">
        @csrf
        @if ($product->exists) @method('PUT') @endif
        <label class="block">Name
            <input name="name" value="{{ old('name', $product->name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
        </label>
        <label class="block">Description
            <textarea name="description" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">{{ old('description', $product->description) }}</textarea>
        </label>
        <input type="hidden" name="active" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $product->exists ? $product->active : true))> Available for new orders</label>
        <input type="hidden" name="taxable" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="taxable" value="1" @checked(old('taxable', $product->exists ? $product->taxable : true))> Taxable</label>

        <div>
            <h2 class="mb-1 font-semibold">Pricing ({{ config('billing.currency') }})</h2>
            <p class="mb-3 text-ink-500">Leave a price blank to not offer that billing cycle.</p>
            <table class="text-sm">
                <thead class="text-left text-ink-500"><tr><th class="pr-4">Cycle</th><th class="pr-4">Price</th><th>Setup fee</th></tr></thead>
                <tbody>
                    @foreach (App\Enums\BillingCycle::cases() as $cycle)
                        @php $price = $prices[$cycle->value] ?? null; @endphp
                        <tr>
                            <td class="py-1 pr-4">{{ $cycle->label() }}</td>
                            <td class="py-1 pr-4"><label for="price-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} price</label><input id="price-{{ $cycle->value }}" name="prices[{{ $cycle->value }}][price]" value="{{ old("prices.{$cycle->value}.price", $price ? Money::toInput($price->price) : '') }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                            <td class="py-1"><label for="setup-fee-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} setup fee</label><input id="setup-fee-{{ $cycle->value }}" name="prices[{{ $cycle->value }}][setup_fee]" value="{{ old("prices.{$cycle->value}.setup_fee", $price && $price->setup_fee ? Money::toInput($price->setup_fee) : '') }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-ink-300"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($modules->isNotEmpty())
            <fieldset class="space-y-3">
                <legend class="mb-1 font-semibold">Provisioning</legend>
                <label class="block">Module
                    <select name="module" id="product-module" class="mt-1 block rounded-md px-3 py-2 ring-1 ring-ink-300">
                        <option value="">None (set up by hand)</option>
                        @foreach ($modules as $key => $module)
                            <option value="{{ $key }}" @selected(old('module', $product->module) === $key)>{{ $module->label() }}</option>
                        @endforeach
                    </select>
                </label>
                @foreach ($modules as $key => $module)
                    @foreach ($module->configFields() as $name => $field)
                        <label class="block" data-module="{{ $key }}">{{ $module->label() }}: {{ $field['label'] }}@if ($field['required'] ?? false) <span class="text-ink-500">(required)</span>@endif
                            <input name="module_config[{{ $key }}][{{ $name }}]" value="{{ old("module_config.{$key}.{$name}", $product->module === $key ? ($product->module_config[$name] ?? '') : '') }}" class="mt-1 block w-full max-w-md rounded-md px-3 py-2 ring-1 ring-ink-300">
                            @if ($field['help'] ?? null)<span class="text-ink-500">{{ $field['help'] }}</span>@endif
                        </label>
                    @endforeach
                @endforeach
            </fieldset>
            <script>
                // Show only the settings of the chosen module.
                (() => {
                    const select = document.getElementById('product-module');
                    const sync = () => document.querySelectorAll('[data-module]').forEach(el => { el.hidden = el.dataset.module !== select.value; });
                    select.addEventListener('change', sync);
                    sync();
                })();
            </script>
        @endif

        <button class="rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Save</button>
    </form>
</x-admin-layout>
