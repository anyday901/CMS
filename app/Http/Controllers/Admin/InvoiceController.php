<?php

namespace App\Http\Controllers\Admin;

use App\Billing\CreditApplier;
use App\Billing\InvoiceCanceller;
use App\Billing\InvoiceEditor;
use App\Billing\InvoicePdf;
use App\Billing\PaymentRecorder;
use App\Billing\RefundService;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
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
use Symfony\Component\HttpFoundation\Response;

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

    public function create(Client $client): View
    {
        return view('admin.invoices.form', [
            'client' => $client,
            'invoice' => new Invoice(['issue_date' => today(), 'due_date' => today()->addDays(7)]),
            'items' => collect(),
        ]);
    }

    public function store(Request $request, Client $client, InvoiceEditor $editor): RedirectResponse
    {
        try {
            $invoice = $editor->create($client, $this->validatedInvoice($request), $request->input('save') === 'draft');
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', 'Invoice created.');
    }

    public function edit(Invoice $invoice): View|RedirectResponse
    {
        if (! $invoice->isEditable()) {
            return redirect()->route('admin.invoices.show', $invoice)->with('status', 'Only draft and unpaid invoices can be edited.');
        }

        return view('admin.invoices.form', ['client' => $invoice->client, 'invoice' => $invoice, 'items' => $invoice->items]);
    }

    public function update(Request $request, Invoice $invoice, InvoiceEditor $editor): RedirectResponse
    {
        try {
            $editor->update($invoice, $this->validatedInvoice($request));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', 'Invoice updated.');
    }

    public function publish(Invoice $invoice, InvoiceEditor $editor): RedirectResponse
    {
        $editor->publish($invoice);

        return back()->with('status', 'Invoice published. The client can now see and pay it.');
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return $pdf->download($invoice);
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

    /** Validates the invoice form, dropping blank item rows and converting amounts to cents. */
    private function validatedInvoice(Request $request): array
    {
        $rows = collect($request->input('items', []))
            ->filter(fn ($row) => is_array($row) && (filled($row['description'] ?? null) || filled($row['amount'] ?? null)))
            ->values();
        $request->merge(['items' => $rows->all()]);

        $data = $request->validate([
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', Money::signedRule()],
            'items.*.taxable' => ['nullable', 'boolean'],
        ], [
            'items.required' => 'Add at least one line item.',
            'items.*.description.required' => 'Every line with an amount needs a description.',
        ]);

        $data['items'] = collect($data['items'])->map(fn ($item) => [
            'id' => isset($item['id']) ? (int) $item['id'] : null,
            'description' => $item['description'],
            'amount' => Money::parse($item['amount']),
            'taxable' => (bool) ($item['taxable'] ?? false),
        ])->all();

        return $data;
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
