@php use App\Support\Money; @endphp
<x-admin-layout :title="$product->exists ? 'Edit product' : 'Add product'">
    <h1 class="mb-6 text-2xl font-semibold">{{ $product->exists ? 'Edit '.$product->name : 'Add product' }}</h1>
    <form method="POST" action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}"
          class="max-w-3xl space-y-4 rounded-lg border border-gray-200 bg-white p-6 text-sm">
        @csrf
        @if ($product->exists) @method('PUT') @endif
        <label class="block">Name
            <input name="name" value="{{ old('name', $product->name) }}" required class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">
        </label>
        <label class="block">Description
            <textarea name="description" rows="3" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-gray-300">{{ old('description', $product->description) }}</textarea>
        </label>
        <input type="hidden" name="active" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $product->exists ? $product->active : true))> Available for new orders</label>

        <div>
            <h2 class="mb-1 font-semibold">Pricing ({{ config('billing.currency') }})</h2>
            <p class="mb-3 text-gray-500">Leave a price blank to not offer that billing cycle.</p>
            <table class="text-sm">
                <thead class="text-left text-gray-500"><tr><th class="pr-4">Cycle</th><th class="pr-4">Price</th><th>Setup fee</th></tr></thead>
                <tbody>
                    @foreach (App\Enums\BillingCycle::cases() as $cycle)
                        @php $price = $prices[$cycle->value] ?? null; @endphp
                        <tr>
                            <td class="py-1 pr-4">{{ $cycle->label() }}</td>
                            <td class="py-1 pr-4"><label for="price-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} price</label><input id="price-{{ $cycle->value }}" name="prices[{{ $cycle->value }}][price]" value="{{ old("prices.{$cycle->value}.price", $price ? Money::toInput($price->price) : '') }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-gray-300"></td>
                            <td class="py-1"><label for="setup-fee-{{ $cycle->value }}" class="sr-only">{{ $cycle->label() }} setup fee</label><input id="setup-fee-{{ $cycle->value }}" name="prices[{{ $cycle->value }}][setup_fee]" value="{{ old("prices.{$cycle->value}.setup_fee", $price && $price->setup_fee ? Money::toInput($price->setup_fee) : '') }}" placeholder="0.00" class="w-28 rounded-md px-2 py-1 ring-1 ring-gray-300"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button class="rounded-md bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">Save</button>
    </form>
</x-admin-layout>
