<?php

namespace App\Affiliates;

use App\Models\Activity;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Commissions from paid invoices: earned when a referred client pays,
 * approved once their clearing days are up (or by staff), and lowered or
 * voided when the invoice is refunded before they are paid out.
 */
class Commissions
{
    public function __construct(private AffiliateProgram $program) {}

    /**
     * Earns the referrer a pending commission on a paid invoice: the amount
     * before tax, while the program is on and the affiliate active. Nothing
     * for invoices with nothing to pay, or outside the first-invoice or
     * month limits.
     */
    public function invoicePaid(Invoice $invoice): ?AffiliateCommission
    {
        $referral = $this->program->enabled()
            ? AffiliateReferral::with('affiliate.client')->where('client_id', $invoice->client_id)->first()
            : null;

        if ($referral === null || ! $this->earnsOn($referral, $invoice)) {
            return null;
        }

        $affiliate = $referral->affiliate;
        $base = $invoice->subtotal - ($invoice->tax_inclusive ? $invoice->tax : 0);
        [$type, $value] = $affiliate->commissionRate();
        $amount = AffiliateProgram::commission($type, $value, $base);

        if ($amount <= 0) {
            return null;
        }

        $clearing = $this->program->clearingDays();
        $commission = AffiliateCommission::create([
            'affiliate_id' => $affiliate->id,
            'referral_id' => $referral->id,
            'invoice_id' => $invoice->id,
            'description' => "Invoice #{$invoice->number}",
            'amount' => $amount,
            'original_amount' => $amount,
            'currency' => $invoice->currency,
            'status' => $clearing === 0 ? AffiliateCommission::APPROVED : AffiliateCommission::PENDING,
            'available_at' => now()->addDays($clearing),
            'approved_at' => $clearing === 0 ? now() : null,
        ]);
        $commission->setRelation('affiliate', $affiliate)->setRelation('referral', $referral)->setRelation('invoice', $invoice);

        Activity::record('Earned an affiliate commission of '.Money::format($amount, $invoice->currency)." on invoice #{$invoice->number} (client #{$invoice->client_id})", $commission, [], $affiliate->client);
        AffiliateHooks::commissionEarned($commission);

        return $commission;
    }

    /** Whether the referral's affiliate is active, isn't the client themselves, and hasn't earned on this invoice yet. */
    private function earnsOn(AffiliateReferral $referral, Invoice $invoice): bool
    {
        $affiliate = $referral->affiliate;

        return $affiliate !== null && $affiliate->isActive() && $affiliate->client_id !== $invoice->client_id
            && ! AffiliateCommission::where('invoice_id', $invoice->id)->exists()
            // Their money only goes into credit; commission comes when that pays an invoice.
            && ! $invoice->isAddFunds() && ! $invoice->isMassPayment()
            && $this->withinLimits($referral, $invoice);
    }

    private function withinLimits(AffiliateReferral $referral, Invoice $invoice): bool
    {
        $months = $this->program->months();

        if ($months !== null && ($invoice->paid_at ?? now())->greaterThan($referral->created_at->copy()->addMonthsNoOverflow($months))) {
            return false;
        }

        return ! $this->program->firstInvoiceOnly() || ! $referral->commissions()->exists();
    }

    /** Approves pending commissions whose clearing date has come. Run nightly by affiliates:clear. */
    public function clear(CarbonInterface $now): int
    {
        return AffiliateCommission::where('status', AffiliateCommission::PENDING)->where('available_at', '<=', $now)
            ->update(['status' => AffiliateCommission::APPROVED, 'approved_at' => $now, 'updated_at' => $now]);
    }

    /** Staff approve a pending commission before its clearing date. */
    public function approve(AffiliateCommission $commission): void
    {
        DB::transaction(function () use ($commission) {
            $commission = AffiliateCommission::lockForUpdate()->findOrFail($commission->id);

            if ($commission->status !== AffiliateCommission::PENDING) {
                throw new InvalidArgumentException('Only pending commissions can be approved.');
            }

            $commission->update(['status' => AffiliateCommission::APPROVED, 'approved_at' => now()]);
            Activity::record('Approved an affiliate commission of '.Money::format($commission->amount, $commission->currency).($commission->description ? " ({$commission->description})" : ''), $commission, [], $commission->affiliate->client);
        });
    }

    /**
     * Voids a commission that hasn't been paid out. One waiting in a payout
     * request comes out of it, and the request shrinks to match.
     */
    public function void(AffiliateCommission $commission, string $reason): void
    {
        DB::transaction(function () use ($commission, $reason) {
            $commission = AffiliateCommission::lockForUpdate()->findOrFail($commission->id);

            if (! $commission->canBeVoided()) {
                throw new InvalidArgumentException($commission->status === AffiliateCommission::PAID
                    ? 'This commission was already paid out, so it can\'t be voided.'
                    : 'This commission is already void.');
            }

            $this->takeFromPayout($commission, $commission->amount);
            $commission->update(['status' => AffiliateCommission::VOID, 'void_reason' => $reason, 'payout_id' => null]);
            Activity::record('Voided an affiliate commission of '.Money::format($commission->amount, $commission->currency).": {$reason}", $commission, [], $commission->affiliate->client);
        });
    }

    /**
     * A refund on an invoice lowers its commission to the share of the
     * invoice still paid, and voids it once nothing is. Paid commissions
     * stay as they are, with a note in the activity log for staff.
     */
    public function refunded(Transaction $refund): void
    {
        $commission = AffiliateCommission::with('affiliate.client')->where('invoice_id', $refund->invoice_id)->first();
        $invoice = Invoice::find($refund->invoice_id);

        if ($commission === null || $invoice === null || $commission->status === AffiliateCommission::VOID) {
            return;
        }

        if ($commission->status === AffiliateCommission::PAID) {
            Activity::record("Invoice #{$invoice->number} was refunded after its affiliate commission of ".Money::format($commission->amount, $commission->currency).' was paid out; take it back by hand if you need to', $commission, [], $commission->affiliate->client);

            return;
        }

        $paid = max(0, min($invoice->total, $invoice->amountPaid()));
        $kept = $invoice->total > 0 ? intdiv($commission->original_amount * $paid * 2 + $invoice->total, 2 * $invoice->total) : 0;

        if ($kept <= 0) {
            $this->void($commission, "Invoice #{$invoice->number} was refunded");

            return;
        }

        if ($kept < $commission->amount) {
            DB::transaction(function () use ($commission, $kept, $invoice) {
                $this->takeFromPayout($commission, $commission->amount - $kept);
                Activity::record('Lowered an affiliate commission from '.Money::format($commission->amount, $commission->currency).' to '.Money::format($kept, $commission->currency)." after a refund on invoice #{$invoice->number}", $commission, [], $commission->affiliate->client);
                $commission->update(['amount' => $kept]);
            });
        }
    }

    /** Takes an amount off the payout request a commission is waiting in; a request left with nothing is rejected. */
    private function takeFromPayout(AffiliateCommission $commission, int $amount): void
    {
        $payout = $commission->payout_id ? AffiliatePayout::lockForUpdate()->find($commission->payout_id) : null;

        if ($payout === null || $payout->status !== AffiliatePayout::REQUESTED) {
            return;
        }

        $payout->amount -= $amount;
        if ($payout->amount <= 0) {
            $payout->amount = 0;
            $payout->status = AffiliatePayout::REJECTED;
            $payout->notes = 'Nothing left to pay: its commissions were voided.';
        }
        $payout->save();
    }
}
