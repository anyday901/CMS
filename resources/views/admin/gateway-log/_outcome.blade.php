@php
    $color = match ($entry->outcome) {
        App\Models\GatewayLog::ACCEPTED => 'bg-brand-100 text-brand-800',
        App\Models\GatewayLog::REJECTED => 'bg-orange-100 text-orange-800',
        default => 'bg-red-100 text-red-800',
    };
@endphp
<span class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $color }}">{{ App\Models\GatewayLog::OUTCOMES[$entry->outcome] ?? $entry->outcome }}</span>
