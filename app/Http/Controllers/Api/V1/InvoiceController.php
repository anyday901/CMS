<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\TransactionResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Invoices through the REST API, and recording a payment made outside a gateway, as on the admin invoice page. */
class InvoiceController extends ApiController
{
    /** The same methods staff can pick when recording a payment by hand. */
    public const METHODS = ['cash', 'check', 'bank_transfer', 'paypal', 'venmo', 'cashapp', 'other'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
        ]);

        $invoices = Invoice::query()
            ->when($filters['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return InvoiceResource::make($invoice->load(['items', 'transactions']));
    }

    /** The amount is in cents. Anything over the balance goes to the client's credit, as it does for staff. */
    public function pay(Request $request, Invoice $invoice, PaymentRecorder $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(self::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payment = $payments->record($invoice, (int) $data['amount'], $data['method'], ($data['reference'] ?? null) ?: null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return TransactionResource::make($payment)->additional([
            'invoice' => InvoiceResource::make($invoice->refresh()),
        ])->response()->setStatusCode(201);
    }
}
