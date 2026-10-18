{{-- The credit limit cell of the client page's account card. $client, $stats['owed'] (what's unpaid) --}}
@php
    $limit = $client->creditLimit();
    $source = match ($client->creditLimitSource()) {
        App\Models\Client::CREDIT_LIMIT_GROUP => 'from their group',
        App\Models\Client::CREDIT_LIMIT_DEFAULT => 'the default',
        default => null,
    };
    $used = $limit > 0 ? min(100, intdiv($stats['owed'] * 100, $limit)) : null;
@endphp
@if ($limit === null)
    None
@else
    {{ App\Support\Money::format($stats['owed'], $client->currency) }} of {{ App\Support\Money::format($limit, $client->currency) }} used
    @if ($source)<span class="text-ink-500">· {{ $source }}</span>@endif
    @if ($used !== null)
        <svg class="mt-1 block h-1.5 w-full max-w-48" viewBox="0 0 100 6" preserveAspectRatio="none" role="img" aria-label="{{ $used }}% of the credit limit used">
            <rect width="100" height="6" rx="3" class="fill-ink-100" />
            <rect width="{{ $used }}" height="6" rx="3" class="{{ $stats['owed'] > $limit ? 'fill-red-600' : 'fill-brand-500' }}" />
        </svg>
        @if ($stats['owed'] > $limit)<div class="text-xs text-red-700">Over the limit: overdue services can be suspended.</div>@endif
    @endif
@endif
