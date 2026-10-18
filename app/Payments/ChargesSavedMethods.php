<?php

namespace App\Payments;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;

/**
 * A gateway that can keep a client's card (or other method) and charge it
 * later without the client there, such as on an invoice's due date. The
 * billing core only talks to this interface; how a method is added is up
 * to the gateway's own widget.
 */
interface ChargesSavedMethods
{
    /**
     * Charges the invoice's balance to a saved method, with the client not
     * present. Returns null when the gateway accepted the charge but is
     * still processing it; its webhook records the payment later. The same
     * idempotency key never charges twice.
     *
     * @throws PaymentFailed when the charge is declined or can't be made. The message is safe to show the client.
     */
    public function charge(Invoice $invoice, PaymentMethod $method, string $idempotencyKey): ?Transaction;

    /**
     * Removes a saved method at the gateway, so it can't be charged again.
     *
     * @throws PaymentFailed when the gateway refuses.
     */
    public function removeMethod(PaymentMethod $method): void;

    /** Blade view for adding a method on the portal's Payment methods page. */
    public function addMethodView(): string;

    /** Extra data for that view. */
    public function addMethodViewData(Client $client): array;
}
