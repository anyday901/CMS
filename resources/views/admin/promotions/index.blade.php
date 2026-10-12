<x-admin-layout title="Promotions">
    <p class="mb-2 text-sm"><a href="{{ route('admin.products.index') }}" class="text-brand-600">Products</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Promotion codes</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Clients enter a code in their cart. It takes a percent or fixed amount off the price of the products it covers (not setup fees), on the first payment or on every renewal. Fixed amounts are in {{ config('billing.currency') }} and only work for clients billed in it. Each checkout that uses a code counts as one use.</p>

    <div class="grid gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            @if ($promotions->isEmpty())
                <p class="text-sm text-ink-500">No promotion codes yet.</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-ink-200 bg-white">
                    <table class="min-w-full text-sm">
                        <thead class="bg-ink-50 text-left text-ink-500"><tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Discount</th><th class="px-4 py-2">Uses</th><th class="px-4 py-2">Expires</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr></thead>
                        <tbody class="divide-y divide-ink-100">
                            @foreach ($promotions as $code)
                                <tr>
                                    <td class="px-4 py-2"><a href="{{ route('admin.promotions.edit', $code) }}" class="font-medium text-brand-600">{{ $code->code }}</a>
                                        <div class="text-ink-500">{{ $code->product_ids ? collect($code->product_ids)->map(fn ($id) => $products[$id] ?? null)->filter()->join(', ') : 'All products' }}</div></td>
                                    <td class="px-4 py-2">{{ $code->summary() }}</td>
                                    <td class="px-4 py-2">{{ $code->uses }}{{ $code->max_uses ? " of {$code->max_uses}" : '' }}{{ $code->once_per_client ? ', once per client' : '' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ $code->expires_on?->format('M j, Y') ?? 'Never' }}</td>
                                    <td class="px-4 py-2"><x-status-badge :status="! $code->active ? 'inactive' : ($code->expires_on?->isPast() && ! $code->expires_on->isToday() ? 'expired' : 'active')" /></td>
                                    <td class="px-4 py-2 text-right">
                                        @if ($code->uses === 0)
                                            <form method="POST" action="{{ route('admin.promotions.destroy', $code) }}" onsubmit="return confirm('Delete this code?')">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600 hover:underline">Delete</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.promotions.store') }}" class="h-fit space-y-3 rounded-lg border border-ink-200 bg-white p-4 text-sm">
            @csrf
            <h2 class="font-semibold">Add a code</h2>
            @include('admin.promotions.fields')
            <button class="w-full rounded-full bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-500">Add code</button>
        </form>
    </div>
</x-admin-layout>
