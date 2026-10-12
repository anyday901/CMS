<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Fraud = 'fraud';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Active => 'Accepted',
            self::Cancelled => 'Cancelled',
            self::Fraud => 'Fraud',
        };
    }
}
