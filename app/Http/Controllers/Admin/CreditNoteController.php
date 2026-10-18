<?php

namespace App\Http\Controllers\Admin;

use App\Billing\CreditApplier;
use App\Billing\CreditNoteIssuer;
use App\Billing\CreditNotePdf;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Payments\GatewayRegistry;
use App\Payments\RefundsPayments;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Issuing a credit note against an invoice, and its PDF. */
class CreditNoteController extends Controller
{
    public function create(Invoice $invoice): View|RedirectResponse
    {
        if (! CreditNoteIssuer::canCredit($invoice)) {
            return redirect()->route('admin.invoices.show', $invoice)->with('status', 'Credit notes can only be issued against paid or part paid invoices.');
        }

        $invoice->load(['client', 'items', 'transactions']);

        return view('admin.credit-notes.create', [
            'invoice' => $invoice,
            'left' => CreditNoteIssuer::creditableLines($invoice),
            'most' => min($invoice->amountPaid(), $invoice->total - $invoice->credited()),
            'refundModes' => $this->refundModes($invoice),
        ]);
    }

    public function store(Request $request, Invoice $invoice, CreditNoteIssuer $issuer): RedirectResponse
    {
        $modes = array_keys($this->refundModes($invoice));
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*' => ['nullable', Money::rule()],
            'reason' => ['required', 'string', 'max:2000'],
            'refund' => ['required', Rule::in($modes)],
        ], ['refund.in' => 'Choose how to give the money back.']);

        // "credit", or "gateway:12" / "manual:12" for a refund of payment 12.
        [$mode, $paymentId] = array_pad(explode(':', $data['refund'], 2), 2, null);
        $lines = collect($data['lines'])->map(fn ($amount) => filled($amount) ? Money::parse($amount) : 0)->all();

        try {
            $note = $issuer->issue($invoice, $lines, $data['reason'], $mode, $paymentId ? $invoice->transactions()->find($paymentId) : null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        $pending = $note->refunds()->where('pending', true)->exists();

        return redirect()->route('admin.invoices.show', $invoice)->with('status', "Credit note {$note->number} issued for ".Money::format($note->total, $note->currency).'.'
            .($pending ? ' The gateway is still processing the refund; the nightly billing run will confirm it.' : ''));
    }

    public function pdf(CreditNote $creditNote, CreditNotePdf $pdf): Response
    {
        return $pdf->download($creditNote);
    }

    /**
     * The ways to give the money back: to account credit, or as a refund of
     * one payment, through its gateway when it can, or as sent outside the app.
     *
     * @return array<string, string> form value => label
     */
    private function refundModes(Invoice $invoice): array
    {
        $gateways = app(GatewayRegistry::class)->enabled()
            ->filter(fn ($gateway) => $gateway instanceof RefundsPayments)
            ->map(fn ($gateway) => $gateway->key())
            ->all();
        $modes = ['credit' => 'Add to the client\'s account credit'];

        foreach ($invoice->transactions()->where('amount', '>', 0)->where('gateway', '!=', CreditApplier::GATEWAY)->get() as $payment) {
            if ($payment->refundable() <= 0) {
                continue;
            }

            $paid = "{$payment->methodLabel()} payment of ".Money::format($payment->amount, $payment->currency).' on '.$payment->created_at->format('M j, Y')
                .' ('.Money::format($payment->refundable(), $payment->currency).' left)';

            if (in_array($payment->gateway, $gateways, true) && $payment->gateway_reference) {
                $modes["gateway:{$payment->id}"] = "Send back through {$payment->methodLabel()}: {$paid}";
            }

            $modes["manual:{$payment->id}"] = "Already refunded outside the app: {$paid}";
        }

        return $modes;
    }
}
