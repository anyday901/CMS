@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;
    $color = match ($value) {
        'active', 'paid', 'done' => 'bg-brand-100 text-brand-800',
        'pending', 'unpaid', 'draft', 'open' => 'bg-sun-300/60 text-amber-900',
        'suspended', 'overdue' => 'bg-orange-100 text-orange-800',
        'terminated', 'cancelled', 'closed', 'refunded' => 'bg-ink-200 text-ink-700',
        default => 'bg-ink-100 text-ink-700',
    };
@endphp
<span {{ $attributes->class("inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {$color}") }}>{{ ucfirst(str_replace('_', ' ', $value)) }}</span>
