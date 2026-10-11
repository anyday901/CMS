<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** SQL that sorts urgent first. */
    public static function orderSql(): string
    {
        return "case priority when 'urgent' then 0 when 'high' then 1 when 'medium' then 2 else 3 end";
    }
}
