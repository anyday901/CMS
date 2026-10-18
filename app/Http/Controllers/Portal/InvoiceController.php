<?php

namespace App\Http\Controllers\Portal;

use App\Billing\AddFunds;
use App\Billing\CreditApplier;
use App\Billing\InvoicePdf;
use App\Billing\MassPayment;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Payments\GatewayRegistry;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function index(Request $request, GatewayRegistry $gateways, MassPayment $massPayments): View
    {
        $client = $request->user('client');
        $invoices = $client->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->latest('issue_date')->latest('id')
            ->paginate(20);

        // Link to the Payment methods page when cards can be saved, or some already are.
        $savedCards = $gateways->savingMethods()->isNotEmpty() || $client->paymentMethods()->exists();

        // Invoices the client can tick to pay together, when there are two or more.
        $massPayable = MassPayment::enabled() ? $massPayments->payable($client)->pluck('id') : collect();
        $massPayable = $massPayable->count() >= 2 ? $massPayable->all() : [];

        return view('portal.invoices.index', [
            'invoices' => $invoices,
            'savedCards' => $savedCards,
            'massPayable' => $massPayable,
            'addFunds' => AddFunds::enabled(),
        ]);
    }

    /** Puts the ticked invoices on one mass payment invoice and takes the client there to pay it. */
    public function massPay(Request $request, MassPayment $massPayments): RedirectResponse
    {
        $data = $request->validate([
            'invoices' => ['required', 'array', 'max:100'],
            'invoices.*' => ['integer'],
        ], ['invoices.required' => __('Choose at least two unpaid invoices to pay together.')]);
        $ids = array_values(array_unique(array_map('intval', $data['invoices'])));

        // One invoice needs no mass payment; pay it as it is.
        if (count($ids) === 1) {
            return redirect()->route('portal.invoices.show', $request->user('client')->invoices()->findOrFail($ids[0]));
        }

        try {
            $invoice = $massPayments->create($request->user('client'), $ids);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('portal.invoices.index')->withErrors(['invoices' => $e->getMessage()]);
        }

        return redirect()->route('portal.invoices.show', $invoice)->with('status', __('This invoice pays the :count invoices you chose. Once it is paid, they are marked paid too.', ['count' => $invoice->items()->count()]));
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

    public function show(Request $request, int $invoice, GatewayRegistry $gateways, MassPayment $massPayments): View
    {
        $invoice = $request->user('client')->invoices()
            ->where('status', '!=', InvoiceStatus::Draft)
            ->with(['items', 'transactions'])
            ->findOrFail($invoice);

        $client = $request->user('client');
        $funds = $invoice->isAddFunds() || $invoice->isMassPayment();
        // The billing run doesn't charge add funds or mass payment invoices.
        $card = $invoice->status === InvoiceStatus::Unpaid && $client->auto_charge && ! $funds ? $client->defaultPaymentMethod() : null;

        return view('portal.invoices.show', [
            'invoice' => $invoice,
            'gateways' => $invoice->status === InvoiceStatus::Unpaid ? $gateways->enabled() : collect(),
            // The card the billing run will charge, when automatic charging applies.
            'autoChargeCard' => $card && $gateways->charger($card->gateway) ? $card : null,
            'addingFunds' => $invoice->isAddFunds(),
            // An unpaid mass payment that also pays this invoice.
            'massPayment' => $invoice->status === InvoiceStatus::Unpaid ? $massPayments->openFor($invoice) : null,
        ]);
    }
}
