<?php

namespace App\Payments;

use App\Models\Transaction;

/**
 * What a charge to a saved method did: the payment recorded, or null while
 * the gateway is still processing it, and the gateway's id for the charge so
 * its later outcome can be matched to the attempt.
 */
final class SavedCharge
{
    public function __construct(public readonly ?Transaction $transaction, public readonly string $reference) {}
}
