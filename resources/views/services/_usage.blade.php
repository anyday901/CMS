{{-- A service's disk and bandwidth use in its latest month, with what its product includes before overages. --}}
@php
    $product = $service->product;
    $billed = $product->overage_billing;
    $meters = [
        'Disk' => [$usage->disk_mb, $usage->disk_limit_mb, $billed ? $product->overage_disk_mb : null, $product->overage_disk_price],
        'Bandwidth in '.$usage->period->format('F') => [$usage->bandwidth_mb, $usage->bandwidth_limit_mb, $billed ? $product->overage_bandwidth_mb : null, $product->overage_bandwidth_price],
    ];
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    @foreach ($meters as $name => [$used, $limit, $included, $price])
        @php $cap = $included ?? $limit; $percent = App\Models\ServiceUsage::percent($used, $cap); @endphp
        <div>
            <div class="text-ink-500">{{ $name }}</div>
            <div>{{ App\Models\ServiceUsage::size($used) }}@if ($cap) of {{ App\Models\ServiceUsage::size($cap) }}{{ $included !== null ? ' included' : '' }}@endif</div>
            @if ($percent !== null)
                <div class="mt-1 h-1.5 w-40 overflow-hidden rounded-full bg-ink-100" aria-hidden="true">
                    <div class="h-full rounded-full {{ $percent >= 90 ? 'bg-orange-500' : 'bg-brand-500' }}" style="width: {{ $percent }}%"></div>
                </div>
            @endif
            @if ($included !== null && $price > 0)
                <div class="mt-1 text-ink-500">Over that is {{ App\Support\Money::format($price, $service->client->currency) }} per GB, billed after the month.</div>
            @endif
        </div>
    @endforeach
</div>
<p class="mt-2 text-ink-500">Last read {{ $usage->read_at?->diffForHumans() ?? 'never' }}.</p>
