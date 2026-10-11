<?php

namespace App\Mail;

use App\Models\EmailTemplate;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The emails the app sends to clients. Each has a default subject and body
 * here; staff can change them under Email templates, and the saved version
 * wins. Bodies are Markdown with {placeholders}.
 */
class EmailTemplates
{
    /** Placeholders every template can use. */
    public const COMMON = ['client_first_name', 'client_name', 'company_name', 'portal_url'];

    /** Put at the top of emails clients can reply to; everything below it is dropped from the reply. */
    public const REPLY_MARKER = '-- Reply above this line --';

    /**
     * Contacts with the 'contacts' flag set get a copy.
     *
     * @return array<string, array{name: string, description: string, subject: string, body: string, placeholders: list<string>, contacts?: string, sensitive?: bool, replyable?: bool}>
     */
    public static function definitions(): array
    {
        return [
            'invoice_created' => [
                'name' => 'New invoice',
                'description' => 'Sent when an invoice is issued: renewals, new orders and invoices staff create or publish. The PDF is attached when that setting is on.',
                'subject' => 'Invoice #{invoice_number} from {company_name}',
                'body' => "Hello {client_first_name},\n\nWe've issued invoice #{invoice_number} for {invoice_total}, due on {invoice_due_date}.\n\n[View and pay the invoice]({invoice_url})\n\nThank you,\n{company_name}",
                'contacts' => 'invoice_emails',
                'placeholders' => ['invoice_number', 'invoice_total', 'invoice_balance', 'invoice_due_date', 'invoice_url'],
            ],
            'invoice_overdue' => [
                'name' => 'Overdue reminder',
                'description' => 'Sent by the nightly billing run when an invoice is overdue by each number of days in BILLING_REMINDER_DAYS.',
                'subject' => 'Reminder: invoice #{invoice_number} is overdue',
                'body' => "Hello {client_first_name},\n\nInvoice #{invoice_number} was due on {invoice_due_date} and {invoice_balance} is still unpaid.\n\n[Pay the invoice]({invoice_url})\n\nIf you've already paid, please ignore this email.\n\nThank you,\n{company_name}",
                'contacts' => 'invoice_emails',
                'placeholders' => ['invoice_number', 'invoice_total', 'invoice_balance', 'invoice_due_date', 'invoice_url', 'days_overdue'],
            ],
            'payment_receipt' => [
                'name' => 'Payment receipt',
                'description' => 'Sent when an invoice is paid in full.',
                'subject' => 'Receipt for invoice #{invoice_number}',
                'body' => "Hello {client_first_name},\n\nThank you for your payment. Invoice #{invoice_number} for {invoice_total} is now paid in full.\n\n[View the invoice]({invoice_url})\n\n{company_name}",
                'contacts' => 'invoice_emails',
                'placeholders' => ['invoice_number', 'invoice_total', 'invoice_url'],
            ],
            'service_welcome' => [
                'name' => 'Service welcome',
                'description' => 'Sent when a service is activated: once its first invoice is paid, or when staff add it without an invoice. Each product can add its own text under Products.',
                'subject' => 'Your {service_name} is ready',
                'body' => "Hello {client_first_name},\n\nThank you for your order. Your {service_name} is now active.\n\n{product_welcome}\n\n[View your service]({service_url})\n\nThank you,\n{company_name}",
                'placeholders' => ['service_name', 'product_name', 'service_url', 'next_due_date', 'recurring_amount', 'product_welcome'],
            ],
            'password_reset' => [
                'name' => 'Password link',
                'description' => 'Sent when a client asks to set or reset their password, or staff send them a password link. The link is left out of the email log.',
                'subject' => 'Set your {company_name} password',
                'body' => "Hello {client_first_name},\n\nUse the link below to set or reset the password for your client account.\n\n[Set password]({reset_url})\n\nThis link expires in {expire_minutes} minutes. If you did not ask for this, you can ignore this email.",
                'placeholders' => ['reset_url', 'expire_minutes'],
                'sensitive' => true,
            ],
            'ticket_opened' => [
                'name' => 'Ticket received',
                'description' => 'Sent when a client opens a ticket, in the portal or by email.',
                'subject' => '[Ticket #{ticket_id}] {ticket_subject}',
                'body' => "Hello {client_first_name},\n\nWe've received your request and will get back to you soon. You can reply to this email to add to it.\n\n[View the ticket]({ticket_url})\n\n{company_name}",
                'contacts' => 'support_emails',
                'placeholders' => ['ticket_id', 'ticket_subject', 'ticket_url', 'department'],
                'replyable' => true,
            ],
            'ticket_reply' => [
                'name' => 'Ticket reply',
                'description' => 'Sent when staff reply to a ticket. Internal notes are never emailed.',
                'subject' => '[Ticket #{ticket_id}] {ticket_subject}',
                'body' => "Hello {client_first_name},\n\n{staff_name} replied to your ticket:\n\n{reply}\n\n[View the ticket]({ticket_url})\n\n{company_name}",
                'contacts' => 'support_emails',
                'placeholders' => ['ticket_id', 'ticket_subject', 'ticket_url', 'department', 'staff_name', 'reply'],
                'replyable' => true,
            ],
        ];
    }

    public static function definition(string $key): array
    {
        return self::definitions()[$key] ?? throw new InvalidArgumentException("Unknown email template {$key}.");
    }

    /**
     * The subject and Markdown body with placeholders filled in, or null when
     * staff turned the template off.
     *
     * @param  array<string, string|int|null>  $values
     * @return array{subject: string, body: string}|null
     */
    public static function render(string $key, array $values): ?array
    {
        $default = self::definition($key);
        $saved = EmailTemplate::find($key);

        if ($saved && ! $saved->enabled) {
            return null;
        }

        $fill = fn (string $text) => preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0],
            $text,
        );

        return [
            'subject' => Str::limit(str_replace(["\r", "\n"], ' ', $fill($saved->subject ?? $default['subject'])), 250, ''),
            'body' => $fill($saved->body ?? $default['body']),
        ];
    }

    /** Markdown to HTML. Raw HTML in the template or in filled-in values is shown as text. */
    public static function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
