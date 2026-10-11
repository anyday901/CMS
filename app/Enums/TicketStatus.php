<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case Answered = 'answered';
    case CustomerReply = 'customer_reply';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::CustomerReply => 'Customer reply',
            self::OnHold => 'On hold',
            default => ucfirst($this->value),
        };
    }

    /** @return list<self> Statuses where the client is waiting on staff. */
    public static function awaitingStaff(): array
    {
        return [self::Open, self::CustomerReply];
    }
}
