<?php

namespace App\Tickets;

use App\Models\Client;
use App\Models\Ticket;

/** Who a ticket or ticket email is from (a client, or someone without an account) and the ticket it answers, if any. */
final readonly class Sender
{
    public function __construct(
        public string $email,
        public string $name,
        public ?Client $client = null,
        public ?Ticket $replyTo = null,
    ) {}
}
