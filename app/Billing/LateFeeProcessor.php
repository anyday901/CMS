<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Adds one late fee to each invoice still unpaid a configured number of days
 * after its due date. Each invoice gets at most one fee, so repeat runs are safe.
 */
class LateFeeProcessor
{
    /** @return int Invoices charged a late fee. */
    public function run(CarbonInterface $today): int
    {
        $days = config('billing.late_fee_after_days');

        if ($days === null || $days === '') {
            return 0;
        }

        $dueOnOrBefore = $today->copy()->subDays((int) $days);

        return Invoice::query()
            ->where('status', InvoiceStatus::Unpaid)
            ->whereDate('due_date', '<=', $dueOnOrBefore)
            ->whereDoesntHave('items', fn ($items) => $items->where('type', InvoiceItem::TYPE_LATE_FEE))
            ->pluck('id')
            ->filter(fn (int $id) => $this->charge($id))
            ->count();
    }

    private function charge(int $invoiceId): bool
    {
        return DB::transaction(function () use ($invoiceId) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoiceId);

            if ($invoice->status !== InvoiceStatus::Unpaid
                || $invoice->items()->where('type', InvoiceItem::TYPE_LATE_FEE)->exists()) {
                return false;
            }

            $fee = $this->feeFor($invoice);

            if ($fee <= 0) {
                return false;
            }

            $invoice->items()->create([
                'type' => InvoiceItem::TYPE_LATE_FEE,
                'description' => 'Late fee',
                'amount' => $fee,
                'taxable' => false,
            ]);
            $invoice->recalculate();

            return true;
        });
    }

    private function feeFor(Invoice $invoice): int
    {
        $amount = Money::parse((string) config('billing.late_fee_amount'));

        // For percentages, parse() reads "10" as 1000, i.e. hundredths of a percent.
        return config('billing.late_fee_type') === 'percent'
            ? intdiv($invoice->total * $amount + 5000, 10000)
            : $amount;
    }
}
