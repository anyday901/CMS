<?php

return [
    /*
     * Shared secret for POST /webhooks/email, which takes piped email over
     * HTTP. Leave blank to turn the endpoint off; the tickets:pipe command
     * works either way.
     */
    'inbound_secret' => env('TICKETS_INBOUND_SECRET'),

    /*
     * Close answered tickets the client hasn't replied to in this many hours.
     * Blank turns it off. Can be changed under Settings.
     */
    'close_inactive_hours' => env('TICKETS_CLOSE_INACTIVE_HOURS'),

    /*
     * Opening hours, for departments whose response time targets count
     * business hours only. Days left out are closed. Times are in the
     * timezone below. All three can be changed under Settings.
     */
    'business_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri'], ['09:00', '17:00']),

    // Dates (YYYY-MM-DD) the business is closed all day, such as public holidays.
    'holidays' => [],

    'timezone' => env('TICKETS_TIMEZONE', 'UTC'),

    /*
     * Let people without a client account open tickets, by emailing a
     * department or with the contact form. They follow their ticket through
     * a private link they're emailed. Can be changed under Settings.
     */
    'guest_tickets' => (bool) env('TICKETS_GUEST_TICKETS', false),

    /*
     * The spam filter for piped ticket email (and the contact form). All of
     * it can be changed under Settings.
     */
    'spam' => [
        // Drop email a spam checker on the mail server (SpamAssassin, Rspamd) marked as spam.
        'use_spam_headers' => true,
        // Addresses (jane@example.com) or whole domains (@example.com) whose email is dropped.
        'blocked_senders' => [],
        // Words or phrases that drop an email when they're in its subject, or in its message.
        'blocked_subjects' => [],
        'blocked_phrases' => [],
        // New tickets one address can open by email in an hour; blank for no limit.
        'new_tickets_per_hour' => 10,
    ],
];
