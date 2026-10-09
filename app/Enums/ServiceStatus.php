<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Cancelled = 'cancelled';

    /** Statuses that keep generating renewal invoices. */
    public static function billable(): array
    {
        return [self::Active, self::Suspended];
    }
}
