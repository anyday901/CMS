<x-admin-layout title="Product groups">
    <p class="mb-2 text-sm"><a href="{{ route('admin.products.index') }}" class="text-brand-600">Products</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Product groups</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Groups are the headings of the <a href="{{ route('store.index') }}" class="text-brand-600">public store</a>. Choose a product's group on the product's page. A hidden group's products can't be ordered.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($groups->isEmpty())
                <p class="text-sm text-ink-500">No groups yet, so the store lists every product together.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Products</th><th class="px-4 py-2">Store</th><th class="px-4 py-2"></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($groups as $productGroup)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.product-groups.edit', $productGroup) }}" class="text-brand-600">{{ $productGroup->name }}</a> <span class="text-ink-500">/store/{{ $productGroup->slug }}</span></td>
                                    <td class="px-4 py-2">{{ $productGroup->products_count }}</td>
                                    <td class="px-4 py-2"><x-status-badge :status="$productGroup->visible ? 'active' : 'inactive'" /></td>
                                    <td class="px-4 py-2 text-right">
                                        <form method="POST" action="{{ route('admin.product-groups.destroy', $productGroup) }}" onsubmit="return confirm('Delete this group? Its products stay, without a group.')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.product-groups.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a group</h2>
            @include('admin.product-groups.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add group</button>
        </form>
    </div>
</x-admin-layout>
