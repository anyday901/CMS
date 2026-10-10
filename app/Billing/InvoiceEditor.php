<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates and edits invoices by hand. Drafts are hidden from the client until
 * published. Unpaid invoices stay editable, but never below what was paid.
 */
class InvoiceEditor
{
    public function __construct(private CreditApplier $credit) {}

    /**
     * @param  array{issue_date: string, due_date: string, notes: ?string, items: list<array{id?: ?int, description: string, amount: int, taxable: bool}>}  $data
     */
    public function create(Client $client, array $data, bool $draft): Invoice
    {
        $invoice = DB::transaction(function () use ($client, $data, $draft) {
            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => $draft ? InvoiceStatus::Draft : InvoiceStatus::Unpaid,
                'currency' => $client->currency,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $invoice->items()->create($this->itemAttributes($item));
            }

            return $this->ensurePositive($invoice->recalculate());
        });

        Activity::record(($draft ? 'Created draft invoice #' : 'Created invoice #').$invoice->number.' for '.Money::format($invoice->total, $invoice->currency), $invoice);

        if (! $draft) {
            $this->credit->applyIfEnabled($invoice);
        }

        return $invoice->refresh();
    }

    /** @param  array{issue_date: string, due_date: string, notes: ?string, items: list<array{id?: ?int, description: string, amount: int, taxable: bool}>}  $data */
    public function update(Invoice $invoice, array $data): Invoice
    {
        $invoice = DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid], true)) {
                throw new InvalidArgumentException("Invoice #{$invoice->number} is {$invoice->status->value} and can't be edited.");
            }

            $invoice->update([
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $keep = collect($data['items'])->pluck('id')->filter()->all();
            $invoice->items()->whereNotIn('id', $keep)->delete();

            foreach ($data['items'] as $item) {
                $attributes = $this->itemAttributes($item);
                isset($item['id'])
                    ? $invoice->items()->whereKey($item['id'])->update($attributes)
                    : $invoice->items()->create($attributes);
            }

            $this->ensurePositive($invoice->recalculate());

            if ($invoice->amountPaid() > 0 && $invoice->balance() <= 0) {
                throw new InvalidArgumentException('The new total must stay above the '.Money::format($invoice->amountPaid(), $invoice->currency).' already paid. Refund the difference instead.');
            }

            return $invoice;
        });

        Activity::record("Edited invoice #{$invoice->number}, total now ".Money::format($invoice->total, $invoice->currency), $invoice);

        return $invoice;
    }

    /** Makes a draft visible to the client and payable. */
    public function publish(Invoice $invoice): Invoice
    {
        $published = DB::transaction(function () use ($invoice) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== InvoiceStatus::Draft) {
                return false;
            }

            $invoice->update(['status' => InvoiceStatus::Unpaid]);

            return true;
        });

        if ($published) {
            Activity::record("Published invoice #{$invoice->number}", $invoice);
            $this->credit->applyIfEnabled($invoice);
        }

        return $invoice->refresh();
    }

    private function ensurePositive(Invoice $invoice): Invoice
    {
        if ($invoice->total <= 0) {
            throw new InvalidArgumentException('The invoice total must be more than zero. Use account credit for money owed to the client.');
        }

        return $invoice;
    }

    private function itemAttributes(array $item): array
    {
        return [
            'description' => $item['description'],
            'amount' => $item['amount'],
            'taxable' => $item['taxable'],
        ];
    }
}
