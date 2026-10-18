<?php

namespace App\Mail;

use App\Jobs\SendLoggedEmail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Support\Language;
use App\Support\Money;
use Illuminate\Support\Traits\Localizable;

/**
 * Sends template emails to clients: fills in the template, writes the email
 * log, and queues the send. Returns null when the template is turned off or
 * the client has no email address. Contacts who asked for that kind of
 * email are copied in.
 */
class ClientMailer
{
    use Localizable;

    /** @param  array<string, string|int|null>  $values */
    public function send(Client $client, string $template, array $values = [], ?Invoice $invoice = null, ?Ticket $ticket = null): ?EmailLog
    {
        if (blank($client->email)) {
            return null;
        }

        // Dates and amounts are written in the client's language too.
        return $this->withLocale(Language::forClient($client), fn () => $this->sendToClient($client, $template, $values, $invoice, $ticket));
    }

    /** @param  array<string, string|int|null>  $values */
    private function sendToClient(Client $client, string $template, array $values, ?Invoice $invoice, ?Ticket $ticket): ?EmailLog
    {
        $definition = EmailTemplates::definition($template);
        $values = array_merge($this->common($client), $invoice ? $this->invoiceValues($invoice) : [], $ticket ? $this->ticketValues($ticket) : [], $values);

        return $this->deliver($template, $values, $client->email, [
            'client_id' => $client->id,
            'invoice_id' => $invoice?->id,
            'ticket_id' => $ticket?->id,
            'cc' => $this->contactCopies($client, $definition['contacts'] ?? null),
            'attach_invoice_pdf' => $invoice !== null && in_array($template, EmailTemplates::INVOICE_TEMPLATES, true) && (bool) config('billing.attach_invoice_pdf'),
            'reply_to' => $ticket?->department->email,
        ]);
    }

    /**
     * A ticket email to whoever opened the ticket: its client, or the guest
     * at the address they wrote from, with their private link to it.
     *
     * @param  array<string, string|int|null>  $values
     */
    public function sendForTicket(Ticket $ticket, string $template, array $values = []): ?EmailLog
    {
        if (! $ticket->isGuest()) {
            return $ticket->client ? $this->send($ticket->client, $template, $values, ticket: $ticket) : null;
        }

        if (blank($ticket->guest_email)) {
            return null;
        }

        // A guest gets the language they wrote in from the contact form, else the main one.
        $locale = in_array(app()->getLocale(), Language::all(), true) ? app()->getLocale() : Language::main();

        return $this->withLocale($locale, fn () => $this->deliver($template, array_merge([
            'client_first_name' => $ticket->requesterFirstName(),
            'client_name' => $ticket->requesterName(),
            'company_name' => config('billing.company.name'),
            'portal_url' => $ticket->guestUrl(),
        ], $this->ticketValues($ticket), $values), $ticket->guest_email, [
            'client_id' => null,
            'invoice_id' => null,
            'ticket_id' => $ticket->id,
            'cc' => null,
            'attach_invoice_pdf' => false,
            'reply_to' => $ticket->department->email,
        ]));
    }

    /**
     * An email to one contact only, such as their portal invitation. The
     * client placeholders are the contact's own name; {account_name} is
     * the account's.
     *
     * @param  array<string, string|int|null>  $values
     */
    public function sendToContact(ClientContact $contact, string $template, array $values = []): ?EmailLog
    {
        $client = $contact->client;

        return $this->withLocale(Language::forClient($client), fn () => $this->deliver($template, array_merge($this->common($client), [
            'client_first_name' => $contact->first_name,
            'client_name' => $contact->fullName(),
            'account_name' => $client->company ?: $client->fullName(),
        ], $values), $contact->email, [
            'client_id' => $client->id,
            'invoice_id' => null,
            'ticket_id' => null,
            'cc' => null,
            'attach_invoice_pdf' => false,
            'reply_to' => null,
        ]));
    }

    /**
     * Fills in the template, logs the email and queues it.
     *
     * @param  array<string, string|int|null>  $values
     * @param  array<string, mixed>  $log  email log columns besides the message itself
     */
    private function deliver(string $template, array $values, string $to, array $log): ?EmailLog
    {
        $definition = EmailTemplates::definition($template);
        $rendered = EmailTemplates::render($template, $values, app()->getLocale());

        if ($rendered === null) {
            return null;
        }

        $body = ($definition['replyable'] ?? false) ? EmailTemplates::REPLY_MARKER."\n\n".$rendered['body'] : $rendered['body'];
        $sensitive = $definition['sensitive'] ?? false;

        $entry = EmailLog::create($log + [
            'template' => $template,
            'to' => $to,
            'subject' => $rendered['subject'],
            'body' => $sensitive ? '(Not kept: this email contains a private link.)' : $body,
            'status' => EmailLog::QUEUED,
        ]);

        SendLoggedEmail::dispatch($entry->id, $sensitive ? $body : null);

        return $entry;
    }

    /** Confirms a store order to the client, with what they ordered and its invoice. */
    public function orderConfirmation(Order $order): ?EmailLog
    {
        $order->loadMissing(['client', 'invoice', 'services' => fn ($query) => $query->with(['product', 'parent.product'])->orderBy('id'), 'domains' => fn ($query) => $query->orderBy('id')]);
        $currency = $order->invoice->currency ?? $order->client->currency;

        return $this->withLocale(Language::forClient($order->client), fn () => $this->send($order->client, 'order_confirmation', [
            'order_number' => $order->id,
            'order_date' => $order->created_at->translatedFormat('M j, Y'),
            'order_items' => $order->services->toBase()->map(fn (Service $service) => '- '.$service->description()
                .($service->billing_cycle->isRecurring() ? ', '.mb_strtolower(__($service->billing_cycle->label())) : '')
                .($service->parent ? ' '.__('(add-on for :service)', ['service' => $service->parent->description()]) : ''))
                ->merge($order->domains->toBase()->map(fn (Domain $domain) => '- '.($domain->type === Domain::TRANSFER
                    ? __('Transfer of :domain, :years year(s)', ['domain' => $domain->name, 'years' => $domain->years])
                    : __('Registration of :domain, :years year(s)', ['domain' => $domain->name, 'years' => $domain->years]))))
                ->join("\n"),
            'order_total' => Money::format($order->invoice->total ?? 0, $currency),
        ], $order->invoice));
    }

    /** Sends a quote to the client, with its PDF attached. */
    public function quote(Quote $quote): ?EmailLog
    {
        $client = $quote->client;

        if (blank($client->email)) {
            return null;
        }

        return $this->withLocale(Language::forClient($client), fn () => $this->deliver('quote_delivered', array_merge($this->common($client), [
            'quote_number' => $quote->number,
            'quote_subject' => $quote->subject,
            'quote_total' => Money::format($quote->total, $quote->currency),
            'quote_valid_until' => $quote->valid_until->translatedFormat('M j, Y'),
            'quote_url' => route('portal.quotes.show', $quote),
        ]), $client->email, [
            'client_id' => $client->id,
            'invoice_id' => null,
            'quote_id' => $quote->id,
            'ticket_id' => null,
            'cc' => $this->contactCopies($client, EmailTemplates::definition('quote_delivered')['contacts']),
            'attach_invoice_pdf' => false,
            'reply_to' => null,
        ]));
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
            'invoice_due_date' => $invoice->due_date->translatedFormat('M j, Y'),
            'invoice_url' => route('portal.invoices.show', $invoice),
        ];
    }

    private function ticketValues(Ticket $ticket): array
    {
        return [
            'ticket_id' => $ticket->id,
            'ticket_subject' => $ticket->subject,
            'ticket_url' => $ticket->viewUrl(),
            'department' => $ticket->department->name,
        ];
    }
}
