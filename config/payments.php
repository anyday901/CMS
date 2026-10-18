<?php

use App\Payments\Gateways\BankTransfer;
use App\Payments\Gateways\CashAppPay;
use App\Payments\Gateways\MailIn;
use App\Payments\Gateways\PayPal;
use App\Payments\Gateways\Stripe;

return [
    // Gateways offered on unpaid invoices in the client portal, in display
    // order. A gateway only shows when its credentials below are set.
    'gateways' => [
        Stripe::class,
        PayPal::class,
        CashAppPay::class,
        BankTransfer::class,
        MailIn::class,
    ],

    // Cards through Stripe, saved for automatic charging. Keys are in the
    // Stripe dashboard under Developers > API keys; test keys (pk_test_,
    // sk_test_) take test cards only. Can also be set under Settings >
    // Payment gateways, which wins over these.
    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        // The signing secret (whsec_...) of the webhook endpoint for /webhooks/stripe.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Seconds a webhook's signed timestamp may be off from this server's clock.
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        // The API version requests are made with, so responses keep the shape this app expects.
        'api_version' => env('STRIPE_API_VERSION', '2024-06-20'),
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

    // Pay outside the app. Each shows on unpaid invoices (and their PDFs)
    // once it has instructions, such as your bank details or the address to
    // post a cheque to; staff record the payment when it arrives. Can also be
    // set on the admin Settings page.
    'bank_transfer' => [
        'instructions' => env('PAYMENTS_BANK_TRANSFER_INSTRUCTIONS'),
    ],

    'mail_in' => [
        'instructions' => env('PAYMENTS_MAIL_IN_INSTRUCTIONS'),
    ],
];
