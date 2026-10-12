@php use App\Support\Money; @endphp
<x-portal-layout title="Upgrade or downgrade">
    <p class="mb-2 text-sm"><a href="{{ route('portal.services.show', $service) }}" class="text-brand-600">{{ $service->description() }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">Upgrade or downgrade</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">Move this service to another product, still billed {{ strtolower($service->billing_cycle->label()) }}. You pay the difference for the rest of the period you've paid for, up to {{ $service->next_due_date?->format('M j, Y') }}; if the new product costs less, the difference goes to your account credit.</p>

    @if ($problem)
        <p class="rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">{{ $problem }}</p>
    @elseif ($products->isEmpty())
        <p class="text-sm text-ink-500">There's nothing to move this service to right now.</p>
    @else
        <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            <div class="flex flex-wrap items-center gap-3 bg-ink-50 px-4 py-3">
                <div><div class="font-medium">{{ $service->product->name }} <span class="font-normal text-ink-500">(now)</span></div></div>
                <span class="ml-auto">{{ Money::format($service->netRecurring(), $service->client->currency) }} {{ strtolower($service->billing_cycle->label()) }}</span>
            </div>
            @foreach ($products as $product)
                @php $price = $product->priceFor($service->billing_cycle, $service->client->currency); @endphp
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div><div class="font-medium">{{ $product->name }}</div>@if ($product->description)<div class="text-ink-500">{{ Str::limit($product->description, 160) }}</div>@endif</div>
                    <span class="ml-auto">{{ Money::format($price->price, $service->client->currency) }} {{ strtolower($service->billing_cycle->label()) }}</span>
                    <a href="{{ route('portal.services.upgrade.show', [$service, $product]) }}" class="rounded-full bg-brand-600 px-3 py-1.5 font-medium text-white hover:bg-brand-500">Choose</a>
                </div>
            @endforeach
        </div>
    @endif
</x-portal-layout>
