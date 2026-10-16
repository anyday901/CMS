@php use App\Support\Money; @endphp
<x-portal-layout :title="__('Upgrade or downgrade')">
    <p class="mb-2 text-sm"><a href="{{ route('portal.services.show', $service) }}" class="text-brand-600">{{ $service->description() }}</a></p>
    <h1 class="mb-2 text-2xl font-semibold">{{ __('Upgrade or downgrade') }}</h1>
    <p class="mb-6 max-w-2xl text-sm text-ink-600">{{ __("Move this service to another product, still billed :cycle. You pay the difference for the rest of the period you've paid for, up to :date; if the new product costs less, the difference goes to your account credit.", ['cycle' => Str::lower(__($service->billing_cycle->label())), 'date' => $service->next_due_date?->translatedFormat('M j, Y')]) }}</p>

    @if ($problem)
        <p class="rounded-md bg-orange-50 px-4 py-3 text-sm text-orange-800">{{ $problem }}</p>
    @elseif ($products->isEmpty())
        <p class="text-sm text-ink-500">{{ __("There's nothing to move this service to right now.") }}</p>
    @else
        <div class="divide-y divide-ink-100 rounded-lg border border-ink-200 bg-white text-sm">
            <div class="flex flex-wrap items-center gap-3 bg-ink-50 px-4 py-3">
                <div><div class="font-medium">{{ $service->product->name }} <span class="font-normal text-ink-500">{{ __('(now)') }}</span></div></div>
                <span class="ml-auto">{{ Money::format($service->netRecurring(), $service->client->currency) }} {{ Str::lower(__($service->billing_cycle->label())) }}</span>
            </div>
            @foreach ($products as $product)
                @php $price = $product->priceFor($service->billing_cycle, $service->client->currency); @endphp
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div><div class="font-medium">{{ $product->name }}</div>@if ($product->description)<div class="text-ink-500">{{ Str::limit($product->description, 160) }}</div>@endif</div>
                    <span class="ml-auto">{{ Money::format($price->price, $service->client->currency) }} {{ Str::lower(__($service->billing_cycle->label())) }}</span>
                    <a href="{{ route('portal.services.upgrade.show', [$service, $product]) }}" class="rounded-full bg-brand-600 px-3 py-1.5 font-medium text-white hover:bg-brand-500">{{ __('Choose') }}</a>
                </div>
            @endforeach
        </div>
    @endif
</x-portal-layout>
