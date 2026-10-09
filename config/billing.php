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
];
