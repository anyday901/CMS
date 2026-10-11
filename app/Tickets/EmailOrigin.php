<?php

namespace App\Tickets;

/** Marks a ticket message as arriving by email, with the email's Message-ID when it has one. */
final readonly class EmailOrigin
{
    public function __construct(public ?string $messageId = null) {}

    /** @return array{via: string, email_message_id: ?string} */
    public function attributes(): array
    {
        return ['via' => 'email', 'email_message_id' => $this->messageId];
    }
}
