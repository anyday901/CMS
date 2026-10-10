<?php

use App\Payments\Gateways\CashAppPay;
use App\Payments\Gateways\PayPal;

return [
    // Gateways offered on unpaid invoices in the client portal, in display
    // order. A gateway only shows when its credentials below are set.
    'gateways' => [
        PayPal::class,
        CashAppPay::class,
    ],

    'paypal' => [
        // "sandbox" for testing, "live" for real payments.
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        // From the webhook you add in the PayPal developer dashboard.
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        // Show the Venmo button (US clients on supported devices only).
        'venmo' => (bool) env('PAYPAL_VENMO', true),
    ],

    'square' => [
        // "sandbox" for testing, "production" for real payments.
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'access_token' => env('SQUARE_ACCESS_TOKEN'),
        'location_id' => env('SQUARE_LOCATION_ID'),
        'version' => env('SQUARE_VERSION', '2024-12-18'),
        // From the webhook subscription in the Square developer dashboard.
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        // The exact notification URL entered in Square. Defaults to this
        // app's /webhooks/square; set it if the app sits behind a proxy.
        'webhook_url' => env('SQUARE_WEBHOOK_URL'),
    ],
];
