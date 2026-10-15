<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A client or guest opened a support ticket. */
class TicketOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Ticket $ticket) {}
}
