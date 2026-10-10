<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Payments\GatewayRefund;
use App\Payments\GatewayRegistry;
use App\Payments\PaymentFailed;
use App\Payments\RefundsPayments;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Checks pending gateway refunds. A completed refund is marked done; a failed
 * one is removed, so the payment counts again and can be refunded again.
 */
class RefundReconciler
{
    public function __construct(private GatewayRegistry $gateways) {}

    /** @return array{completed: int, failed: int} */
    public function run(): array
    {
        $counts = ['completed' => 0, 'failed' => 0];

        foreach (Transaction::where('pending', true)->get() as $refund) {
            $gateway = $this->gateways->find($refund->gateway);

            if (! $gateway instanceof RefundsPayments || $refund->gateway_reference === null) {
                continue;
            }

            try {
                $status = $gateway->refundStatus($refund->gateway_reference)->status;
            } catch (PaymentFailed $e) {
                Log::warning('Could not check pending refund', ['transaction' => $refund->id, 'reason' => $e->getMessage()]);

                continue;
            }

            if ($status === GatewayRefund::COMPLETED) {
                $refund->update(['pending' => false]);
                Activity::record('Gateway confirmed a '.Money::format(-$refund->amount, $refund->currency).' refund', $refund);
                $counts['completed']++;
            } elseif ($status === GatewayRefund::FAILED) {
                $this->undo($refund);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    private function undo(Transaction $refund): void
    {
        DB::transaction(function () use ($refund) {
            $invoice = $refund->invoice_id ? Invoice::lockForUpdate()->find($refund->invoice_id) : null;
            $refund->delete();
            Activity::record(
                'Gateway refund of '.Money::format(-$refund->amount, $refund->currency).' failed, so the payment stands again',
                Transaction::find($refund->refund_of_id),
                ['refund' => $refund->gateway_reference],
                $refund->client,
            );

            if ($invoice && $invoice->status === InvoiceStatus::Refunded && $invoice->amountPaid() > 0) {
                $invoice->forceFill(['status' => InvoiceStatus::Paid])->save();
            }
        });

        Log::warning('Gateway refund failed after being accepted; the payment stands again', [
            'invoice' => $refund->invoice_id, 'payment' => $refund->refund_of_id, 'refund' => $refund->gateway_reference,
        ]);
    }
}
