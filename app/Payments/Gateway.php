<?php

namespace App\Payments;

use App\Models\Invoice;

/**
 * A way for clients to pay an invoice from the portal. Gateways render
 * their own checkout widget and confirm payments through PaymentRecorder,
 * so the billing core never depends on a specific provider.
 */
interface Gateway
{
    /** Stored on transactions, e.g. "paypal". */
    public function key(): string;

    public function label(): string;

    /** True when credentials are configured. */
    public function isEnabled(): bool;

    /** Blade view for the checkout widget, rendered with the invoice. */
    public function view(): string;

    /** Extra data for the view. */
    public function viewData(Invoice $invoice): array;
}
