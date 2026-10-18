<?php

return [
    // Your business details, printed on invoices. Use "\n" for new lines in the address.
    'company' => [
        'name' => env('BILLING_COMPANY_NAME', env('APP_NAME', 'CMS')),
        'address' => env('BILLING_COMPANY_ADDRESS'),
        'email' => env('BILLING_COMPANY_EMAIL'),
        'tax_id' => env('BILLING_COMPANY_TAX_ID'),
    ],

    // The default currency (ISO 4217): new clients get it, and other
    // currencies' exchange rates are against it. It sets the default when
    // CMS is installed; after that, change it under Settings > Currencies.
    'currency' => env('BILLING_CURRENCY', 'USD'),

    // Update exchange rates from the European Central Bank once a day. Can
    // also be set on the admin Automation settings page.
    'update_exchange_rates' => (bool) env('BILLING_UPDATE_EXCHANGE_RATES', false),

    // Generate renewal invoices this many days before a service is due.
    'invoice_days_before_due' => (int) env('BILLING_INVOICE_DAYS_BEFORE_DUE', 7),

    // Days after an invoice's due date before its services are suspended.
    // Set to null to turn off automatic suspension.
    'suspend_after_days' => env('BILLING_SUSPEND_AFTER_DAYS', 3),

    // Days after an invoice's due date before its services are terminated.
    // Set to null to turn off automatic termination.
    'terminate_after_days' => env('BILLING_TERMINATE_AFTER_DAYS', 30),

    // Default credit limit (e.g. "250.00", in the default currency): a client
    // owing no more than this on unpaid invoices isn't suspended or
    // terminated for them. A client's own limit, or their group's, wins.
    // Empty means no limit. Can also be set on the admin Automation page.
    'credit_limit' => env('BILLING_CREDIT_LIMIT'),

    // Days clients have to pay an overage invoice, which billing:run creates
    // after each month for products with usage billing on.
    'overage_due_days' => (int) env('BILLING_OVERAGE_DUE_DAYS', 7),

    // Pay new invoices from the client's credit balance when they are created.
    'apply_credit_automatically' => (bool) env('BILLING_APPLY_CREDIT', true),

    // When true, prices already include tax: invoice totals equal the sum of
    // the lines and show the tax they contain. Applies to invoices created
    // after the change. Can also be set on the admin Settings page.
    'tax_inclusive' => (bool) env('BILLING_TAX_INCLUSIVE', false),

    // Late fee added once to invoices still unpaid this many days after the
    // due date. Set to null to turn late fees off.
    'late_fee_after_days' => env('BILLING_LATE_FEE_AFTER_DAYS'),

    // "fixed" adds BILLING_LATE_FEE_AMOUNT (e.g. "5.00"); "percent" adds that
    // percent of the invoice total (e.g. "10" for 10%).
    'late_fee_type' => env('BILLING_LATE_FEE_TYPE', 'fixed'),
    'late_fee_amount' => env('BILLING_LATE_FEE_AMOUNT', '0'),

    // Attach the invoice PDF to new invoice emails. Can also be set on the
    // admin Settings page.
    'attach_invoice_pdf' => (bool) env('BILLING_ATTACH_INVOICE_PDF', true),

    // Send an overdue reminder when an unpaid invoice is this many days past
    // its due date, once for each number. Empty turns reminders off.
    'reminder_days' => array_values(array_filter(array_map('intval', explode(',', (string) env('BILLING_REMINDER_DAYS', '1,7'))))),

    // Charge unpaid invoices to the client's default saved card this many
    // days before their due date (0: on the due date), in the billing run.
    // Clients can turn automatic charging off in the portal.
    'auto_charge_days_before_due' => (int) env('BILLING_AUTO_CHARGE_DAYS_BEFORE_DUE', 0),

    // Try a failed automatic charge again this many days after the due date,
    // once for each number. Empty means no retries.
    'charge_retry_days' => array_values(array_filter(array_map('intval', explode(',', (string) env('BILLING_CHARGE_RETRY_DAYS', '1,3,5'))))),

    // Keep the services of new store orders pending, even once paid, until
    // staff accept the order in the pending orders queue. Can also be set
    // on the admin Settings page.
    'hold_new_orders' => (bool) env('BILLING_HOLD_NEW_ORDERS', false),
];
