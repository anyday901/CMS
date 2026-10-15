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

    /** The subject of ticket emails; the ticket number in it is how replies find their ticket. */
    public const TICKET_SUBJECT = '[Ticket #{ticket_id}] {ticket_subject}';

    /** Templates that carry an invoice, and get its PDF attached when that setting is on. */
    public const INVOICE_TEMPLATES = ['invoice_created', 'order_invoice'];

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
                'description' => 'Sent when an invoice is issued: renewals, services staff add, and invoices staff create or publish. Store orders use the new order invoice instead. The PDF is attached when that setting is on.',
                'subject' => 'Invoice #{invoice_number} from {company_name}',
                'body' => "Hello {client_first_name},\n\nWe've issued invoice #{invoice_number} for {invoice_total}, due on {invoice_due_date}.\n\n[View and pay the invoice]({invoice_url})\n\nThank you,\n{company_name}",
                'contacts' => 'invoice_emails',
                'placeholders' => ['invoice_number', 'invoice_total', 'invoice_balance', 'invoice_due_date', 'invoice_url'],
            ],
            'order_confirmation' => [
                'name' => 'Order confirmation',
                'description' => 'Sent when a client places an order in the store, listing what they ordered.',
                'subject' => 'Order #{order_number} confirmation',
                'body' => "Hello {client_first_name},\n\nThank you for your order. Here's what you ordered on {order_date}:\n\n{order_items}\n\nOrder total: {order_total}\n\n[View the invoice]({invoice_url})\n\nWe'll let you know when each service is ready.\n\nThank you,\n{company_name}",
                'contacts' => 'invoice_emails',
                'placeholders' => ['order_number', 'order_date', 'order_items', 'order_total', 'invoice_number', 'invoice_url'],
            ],
            'order_invoice' => [
                'name' => 'New order invoice',
                'description' => 'Sent with the first invoice of a store order, when there is something to pay. The PDF is attached when that setting is on.',
                'subject' => 'Invoice #{invoice_number} for order #{order_number}',
                'body' => "Hello {client_first_name},\n\nHere is the invoice for order #{order_number}: {invoice_total}, due on {invoice_due_date}. Your services are set up once it's paid.\n\n[View and pay the invoice]({invoice_url})\n\nThank you,\n{company_name}",
                'contacts' => 'invoice_emails',
                'placeholders' => ['order_number', 'invoice_number', 'invoice_total', 'invoice_balance', 'invoice_due_date', 'invoice_url'],
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
            'email_verification' => [
                'name' => 'Email verification',
                'description' => 'Sent when a client signs up or changes their email address, with a link that confirms the address is theirs. The link is left out of the email log.',
                'subject' => 'Confirm your email address for {company_name}',
                'body' => "Hello {client_first_name},\n\nPlease confirm that this is your email address, so we know our invoices and updates reach you.\n\n[Confirm my email]({verify_url})\n\nThis link expires in {expire_days} days. If you didn't sign up with {company_name}, you can ignore this email.",
                'placeholders' => ['verify_url', 'expire_days'],
                'sensitive' => true,
            ],
            'contact_invite' => [
                'name' => 'Contact portal invitation',
                'description' => 'Sent to a contact when they are given a login to the portal, with a link to choose their password. {client_first_name} is the contact\'s first name here. The link is left out of the email log.',
                'subject' => "You've been given access to {account_name} at {company_name}",
                'body' => "Hello {client_first_name},\n\n{account_name} has given you a login to their account at {company_name}, so you can {contact_permissions}.\n\n[Choose your password]({reset_url})\n\nThis link expires in {expire_hours} hours. After that, use Forgot password on the login page to get a new one.",
                'placeholders' => ['account_name', 'contact_permissions', 'reset_url', 'expire_hours'],
                'sensitive' => true,
            ],
            'ticket_opened' => [
                'name' => 'Ticket received',
                'description' => 'Sent when a client opens a ticket, in the portal or by email, and when someone without an account does (with their private link to the ticket).',
                'subject' => self::TICKET_SUBJECT,
                'body' => "Hello {client_first_name},\n\nWe've received your request and will get back to you soon. You can reply to this email to add to it.\n\n[View the ticket]({ticket_url})\n\n{company_name}",
                'contacts' => 'support_emails',
                'placeholders' => ['ticket_id', 'ticket_subject', 'ticket_url', 'department'],
                'replyable' => true,
            ],
            'ticket_reply' => [
                'name' => 'Ticket reply',
                'description' => 'Sent when staff reply to a ticket. Internal notes are never emailed.',
                'subject' => self::TICKET_SUBJECT,
                'body' => "Hello {client_first_name},\n\n{staff_name} replied to your ticket:\n\n{reply}\n\n[View the ticket]({ticket_url})\n\n{company_name}",
                'contacts' => 'support_emails',
                'placeholders' => ['ticket_id', 'ticket_subject', 'ticket_url', 'department', 'staff_name', 'reply'],
                'replyable' => true,
            ],
            'ticket_auto_closed' => [
                'name' => 'Ticket closed for inactivity',
                'description' => 'Sent when an answered ticket is closed because the client hasn\'t replied in the hours set under Settings.',
                'subject' => self::TICKET_SUBJECT,
                'body' => "Hello {client_first_name},\n\nWe haven't heard back from you in {inactive_hours} hours, so we've closed this ticket. If you still need help, just reply to this email or to the ticket and it will open again.\n\n[View the ticket]({ticket_url})\n\n{company_name}",
                'contacts' => 'support_emails',
                'placeholders' => ['ticket_id', 'ticket_subject', 'ticket_url', 'department', 'inactive_hours'],
                'replyable' => true,
            ],
            'ticket_feedback' => [
                'name' => 'Ticket feedback request',
                'description' => 'Sent when staff close a ticket, or it is closed for inactivity, in a department that asks for feedback.',
                'subject' => 'How did we do with ticket #{ticket_id}?',
                'body' => "Hello {client_first_name},\n\nYour ticket \"{ticket_subject}\" is now closed. Could you tell us how we did? It takes a few seconds and helps us improve.\n\n[Rate our support]({feedback_url})\n\nThank you,\n{company_name}",
                'placeholders' => ['ticket_id', 'ticket_subject', 'ticket_url', 'department', 'feedback_url'],
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
