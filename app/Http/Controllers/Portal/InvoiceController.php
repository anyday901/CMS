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
    public function index(Request $request): View
    {
        $invoices = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->latest('issue_date')->latest('id')
            ->paginate(20);

        return view('portal.invoices.index', compact('invoices'));
    }

    public function applyCredit(Request $request, int $invoice, CreditApplier $credit): RedirectResponse
    {
        $invoice = $request->user('client')->invoices()->findOrFail($invoice);
        $applied = $credit->apply($invoice);

        return redirect()->route('portal.invoices.show', $invoice)->with('status', $applied
            ? 'Applied '.Money::format($applied->amount, $applied->currency).' of your credit to this invoice.'
            : 'There was no credit to apply.');
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
            ->with(['items', 'transactions'])
            ->findOrFail($invoice);

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'gateways' => $invoice->status === InvoiceStatus::Unpaid ? $gateways->enabled() : collect(),
        ]);
    }
}
