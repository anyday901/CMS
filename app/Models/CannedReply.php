<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved ticket answer staff can drop into a reply. */
class CannedReply extends Model
{
    /** Placeholders filled in for the ticket being answered. */
    public const PLACEHOLDERS = ['client_first_name', 'client_name', 'ticket_id', 'ticket_subject', 'department', 'staff_name', 'company_name'];

    protected $fillable = ['title', 'body'];

    /** The body with its placeholders filled in for one ticket and the staff member answering it. */
    public function bodyFor(Ticket $ticket, User $user): string
    {
        $values = [
            'client_first_name' => $ticket->requesterFirstName(),
            'client_name' => $ticket->requesterName(),
            'ticket_id' => $ticket->id,
            'ticket_subject' => $ticket->subject,
            'department' => $ticket->department->name,
            'staff_name' => $user->name,
            'company_name' => config('billing.company.name'),
        ];

        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0],
            $this->body,
        );
    }
}
