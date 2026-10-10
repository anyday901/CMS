<?php

namespace App\Payments;

use App\Models\Transaction;

/** A gateway that can send money back to the payer through its own API. */
interface RefundsPayments
{
    /**
     * Refunds part or all of a payment and returns the gateway's refund id.
     *
     * @throws PaymentFailed when the gateway refuses the refund.
     */
    public function refund(Transaction $payment, int $amount, string $idempotencyKey): string;
}
