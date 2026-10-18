<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lets a client prepay into their credit balance, as WHMCS's "Add Funds"
 * does: an add funds invoice, payable through any gateway, whose payment
 * PaymentRecorder puts into credit instead of paying for anything.
 */
class AddFunds
{
    public static function enabled(): bool
    {
        return (bool) config('billing.add_funds.enabled');
    }

    /**
     * The limits in the client's currency, converted from the default
     * currency's. Null means no limit.
     *
     * @return array{minimum: int, maximum: ?int, maximum_balance: ?int}
     */
    public function limits(Client $client): array
    {
        $limit = function (string $key) use ($client): ?int {
            $value = trim((string) config("billing.add_funds.{$key}"));

            return $value === '' ? null : Currency::convert(Money::parse($value), Currency::defaultCode(), $client->currency);
        };

        return [
            'minimum' => max(1, $limit('minimum') ?? 1),
            'maximum' => $limit('maximum'),
            'maximum_balance' => $limit('maximum_balance'),
        ];
    }

    /** The most the client can add now: the per-payment limit, less any room the balance limit leaves. */
    public function available(Client $client): ?int
    {
        $limits = $this->limits($client);

        if ($limits['maximum_balance'] === null) {
            return $limits['maximum'];
        }

        $room = max(0, $limits['maximum_balance'] - $client->credit_balance - $this->pending($client));

        return $limits['maximum'] === null ? $room : min($limits['maximum'], $room);
    }

    /** Creates an unpaid add funds invoice for $amount cents, due today. */
    public function create(Client $client, int $amount): Invoice
    {
        $invoice = DB::transaction(function () use ($client, $amount) {
            // Serializes a client's requests, so two at once can't both pass the balance limit.
            $client = Client::lockForUpdate()->findOrFail($client->id);
            $this->check($client, $amount);

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => today(),
                'due_date' => today(),
            ]);
            $invoice->items()->create([
                'type' => InvoiceItem::TYPE_ADD_FUNDS,
                'description' => 'Add funds to account credit',
                'amount' => $amount,
                'taxable' => false,
            ]);

            return $invoice->recalculate();
        });

        Activity::record("Created add funds invoice #{$invoice->number} for ".Money::format($amount, $invoice->currency), $invoice);

        return $invoice;
    }

    /** @throws InvalidArgumentException with a message for the client */
    private function check(Client $client, int $amount): void
    {
        if (! self::enabled()) {
            throw new InvalidArgumentException(__('Adding funds is not available.'));
        }

        $limits = $this->limits($client);
        $available = $this->available($client);
        $format = fn (int $cents) => Money::format($cents, $client->currency);

        $error = match (true) {
            $amount < $limits['minimum'] => __('The least you can add is :amount.', ['amount' => $format($limits['minimum'])]),
            $available !== null && $available < $limits['minimum'] => __('Your account credit is at its limit of :amount, so no more can be added.', ['amount' => $format((int) $limits['maximum_balance'])]),
            $available !== null && $amount > $available => __('The most you can add now is :amount.', ['amount' => $format($available)]),
            default => null,
        };

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
    }

    /** What the client's unpaid add funds invoices would add, so they count towards the balance limit. */
    private function pending(Client $client): int
    {
        return (int) InvoiceItem::where('type', InvoiceItem::TYPE_ADD_FUNDS)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('client_id', $client->id)->where('status', InvoiceStatus::Unpaid))
            ->sum('amount');
    }
}
