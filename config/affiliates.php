<?php

return [
    // The affiliate program: referral links, commissions on what referred
    // clients pay, and payouts. Off until turned on here or on the admin
    // Affiliates settings page.
    'enabled' => (bool) env('AFFILIATES_ENABLED', false),

    // "percent" pays AFFILIATES_COMMISSION_AMOUNT percent of each paid
    // invoice before tax (e.g. "10" for 10%); "fixed" pays that amount
    // (e.g. "5.00") per invoice, in the invoice's currency.
    'commission_type' => env('AFFILIATES_COMMISSION_TYPE', 'percent'),
    'commission_amount' => env('AFFILIATES_COMMISSION_AMOUNT', '10'),

    // Pay only on a referred client's first paid invoice, instead of on
    // every one.
    'first_invoice_only' => (bool) env('AFFILIATES_FIRST_INVOICE_ONLY', false),

    // Stop paying on a referred client's invoices this many months after
    // they were referred. Null pays for as long as they stay a client.
    'months' => env('AFFILIATES_MONTHS'),

    // Days a commission waits, in case the invoice is refunded, before it
    // is approved and counts towards the affiliate's balance.
    'clearing_days' => (int) env('AFFILIATES_CLEARING_DAYS', 30),

    // The balance an affiliate needs before they can ask for a payout.
    'minimum_payout' => env('AFFILIATES_MINIMUM_PAYOUT', '25.00'),

    // How long a referral link is remembered in the visitor's browser.
    'cookie_days' => (int) env('AFFILIATES_COOKIE_DAYS', 90),

    // Clients can join from the portal. When off, staff make clients
    // affiliates from the admin area.
    'self_signup' => (bool) env('AFFILIATES_SELF_SIGNUP', true),
];
