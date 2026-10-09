<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Payments\Gateways\CashAppPay;
use App\Payments\Gateways\PayPal;
use App\Payments\PaymentFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function paypalOrder(Request $request, int $invoice, PayPal $paypal): JsonResponse
    {
        $invoice = $this->payable($request, $invoice);
        abort_unless($paypal->isEnabled(), 404);

        try {
            return response()->json(['id' => $paypal->createOrder($invoice)]);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function paypalCapture(Request $request, int $invoice, PayPal $paypal): JsonResponse
    {
        $data = $request->validate(['order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/']]);
        $invoice = $this->payable($request, $invoice);
        abort_unless($paypal->isEnabled(), 404);

        try {
            $transaction = $paypal->captureOrder($invoice, $data['order_id']);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        session()->flash('status', $transaction
            ? 'Thank you, your payment was received.'
            : 'Thank you. PayPal is still processing your payment, and this invoice will update when it clears.');

        return response()->json(['redirect' => route('portal.invoices.show', $invoice)]);
    }

    public function cashApp(Request $request, int $invoice, CashAppPay $cashApp): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $invoice = $this->payable($request, $invoice);
        abort_unless($cashApp->isEnabled(), 404);

        try {
            $cashApp->charge($invoice, $data['token']);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        session()->flash('status', 'Thank you, your payment was received.');

        return response()->json(['redirect' => route('portal.invoices.show', $invoice)]);
    }

    /** The logged-in client's own invoice, if it still has a balance to pay. */
    private function payable(Request $request, int $id): Invoice
    {
        $invoice = $request->user('client')->invoices()->findOrFail($id);

        abort_unless($invoice->status === InvoiceStatus::Unpaid && $invoice->balance() > 0, 409, 'This invoice has nothing left to pay.');

        return $invoice;
    }
}
