{{-- A service's disk and bandwidth use in its latest month, with what its product includes before overages. --}}
@php
    $product = $service->product;
    $billed = $product->overage_billing;
    $meters = [
        __('Disk') => [$usage->disk_mb, $usage->disk_limit_mb, $billed ? $product->overage_disk_mb : null, $product->overage_disk_price],
        __('Bandwidth in :month', ['month' => $usage->period->translatedFormat('F')]) => [$usage->bandwidth_mb, $usage->bandwidth_limit_mb, $billed ? $product->overage_bandwidth_mb : null, $product->overage_bandwidth_price],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    @foreach ($meters as $name => [$used, $limit, $included, $price])
        @php $cap = $included ?? $limit; $percent = App\Models\ServiceUsage::percent($used, $cap); @endphp
        <div>
            <div class="text-ink-500">{{ $name }}</div>
            <div>{{ App\Models\ServiceUsage::size($used) }}@if ($cap) {{ $included !== null ? __('of :size included', ['size' => App\Models\ServiceUsage::size($cap)]) : __('of :size', ['size' => App\Models\ServiceUsage::size($cap)]) }}@endif</div>
            @if ($percent !== null)
                <div class="mt-1 h-1.5 w-40 overflow-hidden rounded-full bg-ink-100" aria-hidden="true">
                    <div class="h-full rounded-full {{ $percent >= 90 ? 'bg-orange-500' : 'bg-brand-500' }}" style="width: {{ $percent }}%"></div>
                </div>
            @endif
            @if ($included !== null && $price > 0)
                <div class="mt-1 text-ink-500">{{ __('Over that is :price per GB, billed after the month.', ['price' => App\Support\Money::format($price, $service->client->currency)]) }}</div>
            @endif
        </div>
    @endforeach
</div>
<p class="mt-2 text-ink-500">{{ __('Last read :time.', ['time' => $usage->read_at?->diffForHumans() ?? __('never')]) }}</p>
