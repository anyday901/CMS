<?php

namespace App\Http\Controllers\Portal;

use App\Billing\CreditApplier;
use App\Billing\InvoicePdf;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Payments\GatewayRegistry;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, GatewayRegistry $gateways): View
    {
        $invoices = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->latest('issue_date')->latest('id')
            ->paginate(20);

        // Link to the Payment methods page when cards can be saved, or some already are.
        $savedCards = $gateways->savingMethods()->isNotEmpty() || $request->user('client')->paymentMethods()->exists();

        $creditNotes = $request->user('client')->creditNotes()->with('invoice')->latest('id')->get();

        return view('portal.invoices.index', compact('invoices', 'savedCards', 'creditNotes'));
    }

    public function applyCredit(Request $request, int $invoice, CreditApplier $credit): RedirectResponse
    {
        $invoice = $request->user('client')->invoices()->findOrFail($invoice);
        $applied = $credit->apply($invoice);

        return redirect()->route('portal.invoices.show', $invoice)->with('status', $applied
            ? __('Applied :amount of your credit to this invoice.', ['amount' => Money::format($applied->amount, $applied->currency)])
            : __('There was no credit to apply.'));
    }

    public function pdf(Request $request, int $invoice, InvoicePdf $pdf): Response
    {
        $invoice = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->findOrFail($invoice);

        return $pdf->download($invoice);
    }

    public function show(Request $request, int $invoice, GatewayRegistry $gateways): View
    {
        $invoice = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->with(['items', 'transactions', 'creditNotes'])
            ->findOrFail($invoice);

        $client = $request->user('client');
        $card = $invoice->status === InvoiceStatus::Unpaid && $client->auto_charge ? $client->defaultPaymentMethod() : null;

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'gateways' => $invoice->status === InvoiceStatus::Unpaid ? $gateways->enabled() : collect(),
            // The card the billing run will charge, when automatic charging applies.
            'autoChargeCard' => $card && $gateways->charger($card->gateway) ? $card : null,
        ]);
    }
}
