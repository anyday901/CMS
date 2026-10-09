@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;
    $color = match ($value) {
        'active', 'paid' => 'bg-green-100 text-green-800',
        'pending', 'unpaid', 'draft' => 'bg-yellow-100 text-yellow-800',
        'suspended', 'overdue' => 'bg-orange-100 text-orange-800',
        'terminated', 'cancelled', 'closed', 'refunded' => 'bg-gray-200 text-gray-700',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp
<span {{ $attributes->class("inline-block rounded-full px-2 py-0.5 text-xs font-medium {$color}") }}>{{ ucfirst(str_replace('_', ' ', $value)) }}</span>
