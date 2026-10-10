<?php

return [
    // Currency for new clients and invoices (ISO 4217).
    'currency' => env('BILLING_CURRENCY', 'USD'),

    // Generate renewal invoices this many days before a service is due.
    'invoice_days_before_due' => (int) env('BILLING_INVOICE_DAYS_BEFORE_DUE', 7),

    // Days after an invoice's due date before its services are suspended.
    // Set to null to turn off automatic suspension.
    'suspend_after_days' => env('BILLING_SUSPEND_AFTER_DAYS', 3),

    // Days after an invoice's due date before its services are terminated.
    // Set to null to turn off automatic termination.
    'terminate_after_days' => env('BILLING_TERMINATE_AFTER_DAYS', 30),

    // Pay new invoices from the client's credit balance when they are created.
    'apply_credit_automatically' => (bool) env('BILLING_APPLY_CREDIT', true),

    // Late fee added once to invoices still unpaid this many days after the
    // due date. Set to null to turn late fees off.
    'late_fee_after_days' => env('BILLING_LATE_FEE_AFTER_DAYS'),

    // "fixed" adds BILLING_LATE_FEE_AMOUNT (e.g. "5.00"); "percent" adds that
    // percent of the invoice total (e.g. "10" for 10%).
    'late_fee_type' => env('BILLING_LATE_FEE_TYPE', 'fixed'),
    'late_fee_amount' => env('BILLING_LATE_FEE_AMOUNT', '0'),
];
