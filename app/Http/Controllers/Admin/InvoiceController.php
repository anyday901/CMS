<?php

namespace App\Http\Controllers\Admin;

use App\Billing\CreditApplier;
use App\Billing\InvoiceCanceller;
use App\Billing\PaymentRecorder;
use App\Billing\RefundService;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Payments\GatewayRegistry;
use App\Payments\RefundsPayments;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        $invoices = Invoice::query()
            ->with('client')
            ->when($status === 'overdue', fn ($q) => $q
                ->where('status', InvoiceStatus::Unpaid)
                ->whereDate('due_date', '<', today()))
            ->when($status && $status !== 'overdue', fn ($q) => $q->where('status', $status))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.invoices.index', compact('invoices', 'status'));
    }

    public function show(Invoice $invoice): View
    {
        $invoice->load(['client', 'items.service', 'transactions.refunds']);

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'refundableByGateway' => app(GatewayRegistry::class)->enabled()
                ->filter(fn ($gateway) => $gateway instanceof RefundsPayments)
                ->map(fn ($gateway) => $gateway->key())
                ->all(),
        ]);
    }

    public function pay(Request $request, Invoice $invoice, PaymentRecorder $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', Money::rule()],
            'method' => ['required', Rule::in(['cash', 'check', 'bank_transfer', 'paypal', 'venmo', 'cashapp', 'other'])],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payments->record($invoice, Money::parse($data['amount']), $data['method'], ($data['reference'] ?? null) ?: null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Payment recorded.');
    }

    public function applyCredit(Request $request, Invoice $invoice, CreditApplier $credit): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', Money::rule()]]);

        $applied = $credit->apply($invoice, Money::parse($data['amount']));

        return back()->with('status', $applied
            ? 'Applied '.Money::format($applied->amount, $applied->currency).' of credit.'
            : 'No credit was applied.');
    }

    public function refund(Request $request, Transaction $transaction, RefundService $refunds): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', Money::rule()],
            'mode' => ['required', Rule::in(RefundService::MODES)],
        ]);

        try {
            $refund = $refunds->refund($transaction, Money::parse($data['amount']), $data['mode']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(["refund.{$transaction->id}" => $e->getMessage()]);
        }

        return back()->with('status', $refund->pending
            ? 'Refund sent. The gateway is still processing it; the nightly billing run will confirm it or undo it if it fails.'
            : 'Refund recorded.');
    }

    public function cancel(Invoice $invoice, InvoiceCanceller $canceller): RedirectResponse
    {
        try {
            $canceller->cancel($invoice);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return back()->with('status', 'Invoice cancelled.');
    }
}
