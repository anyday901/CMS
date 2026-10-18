@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;
    $color = match ($value) {
        'active', 'paid', 'done', 'sent', 'answered', 'up', 'accepted' => 'bg-brand-100 text-brand-800',
        'pending', 'pending_transfer', 'unpaid', 'draft', 'open', 'queued', 'customer_reply', 'delivered' => 'bg-sun-300/60 text-amber-900',
        'suspended', 'overdue', 'on_hold', 'high', 'warning' => 'bg-orange-100 text-orange-800',
        'failed', 'urgent', 'fraud', 'down' => 'bg-red-100 text-red-800',
        'terminated', 'cancelled', 'closed', 'refunded', 'expired', 'transferred_away', 'lost', 'dead' => 'bg-ink-200 text-ink-700',
        default => 'bg-ink-100 text-ink-700',
    };
@endphp
<span {{ $attributes->class("inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold {$color}") }}>{{ $slot->isEmpty() ? __(ucfirst(str_replace('_', ' ', $value))) : $slot }}</span>
