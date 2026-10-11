<?php

namespace App\Mail;

use App\Jobs\SendLoggedEmail;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Ticket;
use App\Support\Money;

/**
 * Sends template emails to clients: fills in the template, writes the email
 * log, and queues the send. Returns null when the template is turned off or
 * the client has no email address. Contacts who asked for that kind of
 * email are copied in.
 */
class ClientMailer
{
    /** @param  array<string, string|int|null>  $values */
    public function send(Client $client, string $template, array $values = [], ?Invoice $invoice = null, ?Ticket $ticket = null): ?EmailLog
    {
        if (blank($client->email)) {
            return null;
        }

        $definition = EmailTemplates::definition($template);
        $values = array_merge($this->common($client), $invoice ? $this->invoiceValues($invoice) : [], $ticket ? $this->ticketValues($ticket) : [], $values);
        $rendered = EmailTemplates::render($template, $values);

        if ($rendered === null) {
            return null;
        }

        $body = ($definition['replyable'] ?? false) ? EmailTemplates::REPLY_MARKER."\n\n".$rendered['body'] : $rendered['body'];
        $sensitive = $definition['sensitive'] ?? false;

        $log = EmailLog::create([
            'client_id' => $client->id,
            'invoice_id' => $invoice?->id,
            'ticket_id' => $ticket?->id,
            'template' => $template,
            'to' => $client->email,
            'cc' => $this->contactCopies($client, $definition['contacts'] ?? null),
            'subject' => $rendered['subject'],
            'body' => $sensitive ? '(Not kept: this email contains a private link.)' : $body,
            'status' => EmailLog::QUEUED,
            'attach_invoice_pdf' => $invoice !== null && $template === 'invoice_created' && (bool) config('billing.attach_invoice_pdf'),
            'reply_to' => $ticket?->department->email,
        ]);

        SendLoggedEmail::dispatch($log->id, $sensitive ? $body : null);

        return $log;
    }

    /**
     * Addresses of the contacts who asked for this kind of email, or null.
     *
     * @return list<string>|null
     */
    private function contactCopies(Client $client, ?string $flag): ?array
    {
        if ($flag === null) {
            return null;
        }

        $emails = $client->contacts()->where($flag, true)->pluck('email')->unique()->values()->all();

        return $emails === [] ? null : $emails;
    }

    private function common(Client $client): array
    {
        return [
            'client_first_name' => $client->first_name,
            'client_name' => $client->fullName(),
            'company_name' => config('billing.company.name'),
            'portal_url' => route('portal.dashboard'),
        ];
    }

    private function invoiceValues(Invoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->number,
            'invoice_total' => Money::format($invoice->total, $invoice->currency),
            'invoice_balance' => Money::format($invoice->balance(), $invoice->currency),
            'invoice_due_date' => $invoice->due_date->format('M j, Y'),
            'invoice_url' => route('portal.invoices.show', $invoice),
        ];
    }

    private function ticketValues(Ticket $ticket): array
    {
        return [
            'ticket_id' => $ticket->id,
            'ticket_subject' => $ticket->subject,
            'ticket_url' => route('portal.tickets.show', $ticket),
            'department' => $ticket->department->name,
        ];
    }
}
