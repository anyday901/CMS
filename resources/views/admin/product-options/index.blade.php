@php use App\Models\ProductOption; use App\Support\Money; @endphp
<x-admin-layout :title="'Options for '.$product->name">
    <h1 class="mb-2 text-2xl font-semibold">Configurable options for <a href="{{ route('admin.products.edit', $product) }}" class="text-brand-600">{{ $product->name }}</a></h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Choices picked when the service is added, such as disk size or backups. Each choice adds its price to the service's recurring amount and its setup fee to the first invoice. Prices are in {{ config('billing.currency') }}.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($options->isEmpty())
                <p class="text-sm text-ink-500">No options yet.</p>
            @else
                <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
                    @foreach ($options as $option)
                        <div class="flex items-start gap-4 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('admin.product-options.edit', $option) }}" class="font-medium text-brand-600">{{ $option->name }}</a>
                                <span class="text-ink-500">{{ ProductOption::TYPES[$option->type] }}</span>
                                <p class="text-ink-500">
                                    @if ($option->hasOneChoice())
                                        @php $first = $option->choices->first(); @endphp
                                        {{ $first && $first->prices ? collect($first->prices)->map(fn ($cents, $cycle) => Money::format($cents).' '.strtolower(App\Enums\BillingCycle::from($cycle)->label()))->join(', ') : 'Free' }}{{ $option->type === ProductOption::QUANTITY ? ' each' : '' }}
                                    @else
                                        {{ $option->choices->pluck('name')->join(', ') ?: 'No choices yet' }}
                                    @endif
                                </p>
                            </div>
                            <form method="POST" action="{{ route('admin.product-options.destroy', $option) }}" onsubmit="return confirm('Delete this option?')">
                                @csrf @method('DELETE')
                                <button class="rounded-full px-3 py-1 text-ink-600 ring-1 ring-ink-300 hover:bg-ink-100">Delete</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.product-options.store', $product) }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add an option</h2>
            <label class="block">Name
                <input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Disk space" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
            </label>
            <label class="block">Type
                <select name="type" class="mt-1 w-full rounded-md px-3 py-2 ring-1 ring-ink-300">
                    @foreach (ProductOption::TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="text-ink-500">Dropdown: pick one of several choices. Checkbox: on or off. Quantity: a number of units, priced per unit.</span>
            </label>
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add option</button>
        </form>
    </div>
</x-admin-layout>
