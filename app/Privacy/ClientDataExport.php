<?php

namespace App\Privacy;

use App\Models\Activity;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CreditEntry;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\KnownDevice;
use App\Models\LoginAttempt;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\Transaction;
use BackedEnum;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything kept about a client, as one JSON document they can be given
 * (a GDPR subject access or portability request). Passwords, two-factor
 * secrets, server logins and staff's internal ticket notes are left out.
 * Amounts are in minor units (cents), in the currency beside them.
 */
class ClientDataExport
{
    /** @return array<string, mixed> */
    public function build(Client $client): array
    {
        $client->load([
            'contacts', 'services.product', 'orders', 'creditEntries',
            'invoices' => fn ($query) => $query->with(['items', 'transactions'])->orderBy('issue_date')->orderBy('id'),
            'tickets' => fn ($query) => $query->with(['department', 'feedback', 'messages' => fn ($query) => $query->where('internal', false)->with(['attachments', 'user', 'client'])->orderBy('id')])->orderBy('id'),
        ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'exported_by' => config('billing.company.name'),
            'note' => 'Amounts are in minor units (cents) of the currency shown. Passwords and security codes are not included.',
            'client' => $this->only($client, [
                'id', 'first_name', 'last_name', 'company', 'email', 'email_verified_at', 'phone', 'address1', 'address2',
                'city', 'state', 'postcode', 'country', 'currency', 'tax_exempt', 'status', 'credit_balance', 'custom_fields',
                'marketing_emails', 'marketing_consent_at', 'created_at',
            ]) + ['group' => $client->group?->name, 'two_factor_enabled' => $client->hasTwoFactor()],
            'contacts' => $client->contacts->map(fn (ClientContact $contact) => $this->only($contact, [
                'first_name', 'last_name', 'email', 'phone', 'invoice_emails', 'support_emails', 'portal_access', 'permissions', 'last_login_at', 'created_at',
            ]))->all(),
            'services' => $client->services->map(fn (Service $service) => [
                'id' => $service->id,
                'product' => $service->product?->name,
            ] + $this->only($service, [
                'label', 'status', 'billing_cycle', 'recurring_amount', 'registration_date', 'next_due_date', 'username', 'custom_fields', 'created_at',
            ]) + [
                'cancellation_requests' => $service->cancellationRequests()->get()->map(fn (CancellationRequest $request) => $this->only($request, ['type', 'reason', 'status', 'cancel_on', 'created_at']))->all(),
            ])->all(),
            'orders' => $client->orders->map(fn (Order $order) => $this->only($order, ['id', 'status', 'promotion_code', 'ip_address', 'created_at']))->all(),
            'invoices' => $client->invoices->map(fn (Invoice $invoice) => $this->only($invoice, [
                'number', 'status', 'currency', 'issue_date', 'due_date', 'subtotal', 'tax', 'total', 'paid_at',
            ]) + [
                'items' => $invoice->items->map(fn (InvoiceItem $item) => $this->only($item, ['description', 'amount', 'period_start', 'period_end']))->all(),
                'payments' => $invoice->transactions->map(fn (Transaction $transaction) => $this->only($transaction, ['gateway', 'payment_method', 'gateway_reference', 'currency', 'amount', 'created_at']))->all(),
            ])->all(),
            'credit' => $client->creditEntries->map(fn (CreditEntry $entry) => $this->only($entry, ['amount', 'balance_after', 'description', 'created_at']))->all(),
            'tickets' => $client->tickets->map(fn (Ticket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'department' => $ticket->department?->name,
                'status' => $ticket->status,
                'created_at' => $this->date($ticket->created_at),
                'feedback' => $ticket->feedback ? $this->only($ticket->feedback, ['rating', 'comment', 'created_at']) : null,
                'messages' => $ticket->messages->map(fn (TicketMessage $message) => [
                    'from' => $message->authorName(),
                    'body' => $message->body,
                    'attachments' => $message->attachments->pluck('name')->all(),
                    'created_at' => $this->date($message->created_at),
                ])->all(),
            ])->all(),
            'emails' => $client->emailLogs()->orderBy('id')->get()->map(fn (EmailLog $email) => $this->only($email, ['to', 'cc', 'subject', 'body', 'status', 'sent_at', 'created_at']))->all(),
            'devices' => KnownDevice::whereIn('email', $client->contacts->pluck('email')->push($client->email)->map(fn ($email) => strtolower((string) $email)))
                ->orderBy('id')->get()->map(fn (KnownDevice $device) => $this->only($device, ['email', 'user_agent', 'ip_address', 'created_at', 'last_used_at']))->all(),
            'logins' => $client->loginAttempts()->orderBy('id')->get()->map(fn (LoginAttempt $login) => $this->only($login, ['email', 'ip_address', 'user_agent', 'outcome', 'created_at']))->all(),
            'activity' => Activity::where('client_id', $client->id)->orderBy('id')->get()->map(fn (Activity $entry) => $this->only($entry, ['actor_name', 'description', 'ip_address', 'created_at']))->all(),
        ];
    }

    public function json(Client $client): string
    {
        return json_encode($this->build($client), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The export as a JSON file to save. */
    public function download(Client $client): Response
    {
        return response($this->json($client), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="client-'.$client->id.'-data-'.now()->format('Y-m-d').'.json"',
        ]);
    }

    /** @param  list<string>  $keys */
    private function only(object $model, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $model->{$key};
            $out[$key] = match (true) {
                $value instanceof DateTimeInterface => $this->date($value),
                $value instanceof BackedEnum => $value->value,
                default => $value,
            };
        }

        return $out;
    }

    private function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }
}
