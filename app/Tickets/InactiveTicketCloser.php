<?php

namespace App\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Closes answered tickets the client hasn't replied to within the hours set
 * under Settings, and tells the client. A reply opens the ticket again.
 */
class InactiveTicketCloser
{
    public function __construct(private TicketDesk $desk) {}

    /** @return int How many tickets were closed. */
    public function run(DateTimeInterface $now): int
    {
        $hours = (int) config('tickets.close_inactive_hours');

        if ($hours <= 0) {
            return 0;
        }

        $closed = 0;
        $tickets = Ticket::listed()
            ->where('status', TicketStatus::Answered->value)
            ->where('last_reply_at', '<=', CarbonImmutable::instance($now)->subHours($hours))
            ->with(['client', 'department']);

        foreach ($tickets->lazyById(200) as $ticket) {
            $this->desk->close($ticket, inactive: true);
            $closed++;
        }

        return $closed;
    }
}
