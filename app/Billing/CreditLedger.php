<?php

namespace App\Billing;

use App\Models\Activity;
use App\Models\Client;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place a client's credit balance changes, so every change has a
 * matching line in the credit history.
 */
class CreditLedger
{
    /**
     * Adds (positive) or removes (negative) credit. Callers that already hold
     * the invoice lock pass through here inside their own transaction.
     */
    public function change(int $clientId, int $amount, string $description, ?Invoice $invoice = null, ?Transaction $transaction = null): CreditEntry
    {
        return DB::transaction(function () use ($clientId, $amount, $description, $invoice, $transaction) {
            Client::whereKey($clientId)->increment('credit_balance', $amount);

            return CreditEntry::create([
                'client_id' => $clientId,
                'amount' => $amount,
                'balance_after' => (int) Client::whereKey($clientId)->value('credit_balance'),
                'description' => $description,
                'invoice_id' => $invoice?->id,
                'transaction_id' => $transaction?->id,
                'user_id' => Auth::guard('web')->id(),
            ]);
        });
    }

    /** A manual adjustment by staff, such as a goodwill credit or a correction. */
    public function adjust(Client $client, int $amount, string $reason): CreditEntry
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Enter an amount other than zero.');
        }

        return DB::transaction(function () use ($client, $amount, $reason) {
            $balance = (int) Client::lockForUpdate()->whereKey($client->id)->value('credit_balance');

            if ($balance + $amount < 0) {
                throw new InvalidArgumentException('That would leave the client with less than zero credit. Their balance is '.Money::format($balance, $client->currency).'.');
            }

            $entry = $this->change($client->id, $amount, $reason);
            Activity::record(($amount > 0 ? 'Added ' : 'Removed ').Money::format(abs($amount), $client->currency)." of credit: {$reason}", $client);

            return $entry;
        });
    }
}
