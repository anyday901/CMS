{{-- The credit limit row of the client page's account card. $client, $stats['owed'] (what's unpaid) --}}
@php
    $limit = $client->creditLimit();
    $source = match ($client->creditLimitSource()) {
        App\Models\Client::CREDIT_LIMIT_GROUP => 'from their group',
        App\Models\Client::CREDIT_LIMIT_DEFAULT => 'the default',
        default => null,
    };
    $used = $limit > 0 ? min(100, intdiv($stats['owed'] * 100, $limit)) : null;
@endphp
<dt class="text-ink-500">Credit limit</dt>
<dd>
    @if ($limit === null)
        None
    @else
        {{ App\Support\Money::format($stats['owed'], $client->currency) }} of {{ App\Support\Money::format($limit, $client->currency) }} used
        @if ($source)<span class="text-ink-500">· {{ $source }}</span>@endif
        @if ($used !== null)
            <div class="mt-1 h-1.5 w-full max-w-48 rounded-full bg-ink-100" role="img" aria-label="{{ $used }}% of the credit limit used">
                <div class="h-1.5 rounded-full {{ $stats['owed'] > $limit ? 'bg-red-600' : 'bg-brand-500' }}" style="width: {{ $used }}%"></div>
            </div>
            @if ($stats['owed'] > $limit)<div class="text-xs text-red-700">Over the limit: overdue services can be suspended.</div>@endif
        @endif
    @endif
</dd>
