<?php

use App\Domains\Registrars\Enom;
use App\Domains\Registrars\Namecheap;
use App\Domains\Registrars\OpenSrs;
use App\Domains\Registrars\ResellerClub;

return [
    // Registrar modules staff can choose on a TLD. Each class implements
    // App\Domains\Registrar; enter their credentials under Settings > Domains.
    'registrars' => [
        Enom::class,
        Namecheap::class,
        OpenSrs::class,
        ResellerClub::class,
        // App\Domains\Registrars\MyRegistrar::class,
    ],

    // Registrar credentials as JSON keyed by registrar, saved encrypted from
    // Settings > Domains.
    'credentials' => null,

    // Nameservers new domains get when the client doesn't give their own.
    'nameservers' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOMAINS_NAMESERVERS', ''))))),

    // Create the renewal invoice for an auto-renewing domain this many days
    // before it expires, so there's time to pay before the registrar's deadline.
    'invoice_days_before_expiry' => (int) env('DOMAINS_INVOICE_DAYS_BEFORE_EXPIRY', 30),

    // Email a renewal reminder this many days before a domain expires, once
    // for each number; an expiry notice is sent when it expires unrenewed.
    'reminder_days' => array_values(array_filter(array_map('intval', explode(',', (string) env('DOMAINS_REMINDER_DAYS', '30,7,1'))))),

    // Whether new domains renew automatically (an invoice each term) until
    // the client turns it off.
    'auto_renew_default' => (bool) env('DOMAINS_AUTO_RENEW', true),

    // Domains asked about on each nightly sync, the longest unsynced first.
    'sync_batch' => (int) env('DOMAINS_SYNC_BATCH', 200),
];
