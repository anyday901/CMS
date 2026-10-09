<?php

namespace App\Http\Controllers\Admin;

use App\Billing\InvoiceCanceller;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
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
        $invoice->load(['client', 'items.service', 'transactions']);

        return view('admin.invoices.show', compact('invoice'));
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
