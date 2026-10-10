@php use App\Support\Money; @endphp
<x-admin-layout title="Products">
    <div class="mb-4 flex items-center">
        <h1 class="text-2xl font-semibold">Products</h1>
        <a href="{{ route('admin.products.create') }}" class="ml-auto rounded-full bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-500">Add product</a>
    </div>
    @if ($products->isEmpty())
        <p class="text-sm text-ink-500">No products yet. Add one to start selling services.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
            <table class="min-w-full text-sm">
                <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Pricing</th><th class="px-4 py-2">Services</th><th class="px-4 py-2">Status</th></tr></thead>
                <tbody class="divide-y divide-ink-100">
                    @foreach ($products as $product)
                        <tr>
                            <td class="px-4 py-2"><a href="{{ route('admin.products.edit', $product) }}" class="text-brand-600">{{ $product->name }}</a></td>
                            <td class="px-4 py-2">{{ $product->prices->sortBy(fn ($p) => $p->billing_cycle->months() ?? 0)->map(fn ($p) => Money::format($p->price, $p->currency).' '.strtolower($p->billing_cycle->label()))->join(', ') ?: '—' }}</td>
                            <td class="px-4 py-2">{{ $product->services_count }}</td>
                            <td class="px-4 py-2"><x-status-badge :status="$product->active ? 'active' : 'inactive'" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-admin-layout>
