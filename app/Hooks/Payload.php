<?php

namespace App\Hooks;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;
use App\Models\Ticket;

/**
 * The data sent with each event to webhooks and plugins: plain arrays of
 * the record's main fields. Money is in minor units (cents) with its
 * currency, dates are ISO 8601.
 */
class Payload
{
    /** @return array<string, mixed> */
    public static function client(Client $client): array
    {
        return [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'company' => $client->company,
            'email' => $client->email,
            'phone' => $client->phone,
            'country' => $client->country,
            'currency' => $client->currency,
            'status' => $client->status?->value,
            'client_group_id' => $client->client_group_id,
            'credit_balance' => (int) $client->credit_balance,
            'marketing_emails' => (bool) $client->marketing_emails,
            'created_at' => $client->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'client_id' => $invoice->client_id,
            'status' => $invoice->status?->value,
            'currency' => $invoice->currency,
            'subtotal' => (int) $invoice->subtotal,
            'tax' => (int) $invoice->tax,
            'total' => (int) $invoice->total,
            'balance' => $invoice->balance(),
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function service(Service $service): array
    {
        return [
            'id' => $service->id,
            'client_id' => $service->client_id,
            'product_id' => $service->product_id,
            'product_name' => $service->product?->name,
            'description' => $service->description(),
            'label' => $service->label,
            'status' => $service->status?->value,
            'billing_cycle' => $service->billing_cycle?->value,
            'recurring_amount' => (int) $service->recurring_amount,
            'registration_date' => $service->registration_date?->toDateString(),
            'next_due_date' => $service->next_due_date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'client_id' => $order->client_id,
            'invoice_id' => $order->invoice_id,
            'status' => $order->status?->value,
            'promotion_code' => $order->promotion_code,
            'service_ids' => $order->services()->pluck('id')->all(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function ticket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'client_id' => $ticket->client_id,
            'guest_email' => $ticket->guest_email,
            'department_id' => $ticket->department_id,
            'department' => $ticket->department?->name,
            'subject' => $ticket->subject,
            'status' => $ticket->status?->value,
            'priority' => $ticket->priority?->value,
            'assigned_to' => $ticket->assigned_to,
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
        ];
    }
}
