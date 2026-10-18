<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Payments\Gateways\CashAppPay;
use App\Payments\Gateways\PayPal;
use App\Payments\Gateways\Stripe;
use App\Payments\PaymentFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
            ? __('Thank you, your payment was received.')
            : __('Thank you. PayPal is still processing your payment, and this invoice will update when it clears.'));

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

        session()->flash('status', __('Thank you, your payment was received.'));

        return response()->json(['redirect' => route('portal.invoices.show', $invoice)]);
    }

    /** Starts a card payment for the Stripe card form, saving the card when the client asked to. */
    public function stripeIntent(Request $request, int $invoice, Stripe $stripe): JsonResponse
    {
        $data = $request->validate(['save' => ['boolean']]);
        $invoice = $this->payable($request, $invoice);
        abort_unless($stripe->isEnabled(), 404);

        try {
            return response()->json(['client_secret' => $stripe->createIntent($invoice, (bool) ($data['save'] ?? false))]);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Records a card payment Stripe.js confirmed in the browser. */
    public function stripeConfirm(Request $request, int $invoice, Stripe $stripe): JsonResponse
    {
        $data = $request->validate(['payment_intent' => ['required', 'string', 'max:255', 'regex:/^pi_[A-Za-z0-9]+$/']]);
        // Not payable(): the webhook may already have recorded this very payment.
        $invoice = $request->user('client')->invoices()->findOrFail($invoice);
        abort_unless($stripe->isEnabled(), 404);

        try {
            $this->flashStripe($stripe->confirm($invoice, $data['payment_intent']) !== null);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['redirect' => route('portal.invoices.show', $invoice)]);
    }

    /** Where Stripe sends the client back after a payment that needed a redirect, such as to their bank. */
    public function stripeReturn(Request $request, int $invoice, Stripe $stripe): RedirectResponse
    {
        $invoice = $request->user('client')->invoices()->findOrFail($invoice);
        $intent = (string) $request->query('payment_intent');
        $back = redirect()->route('portal.invoices.show', $invoice);

        if (! $stripe->isEnabled() || ! preg_match('/^pi_[A-Za-z0-9]+$/', $intent)) {
            return $back;
        }

        try {
            $this->flashStripe($stripe->confirm($invoice, $intent) !== null);
        } catch (PaymentFailed $e) {
            return $back->withErrors(['payment' => $e->getMessage()]);
        }

        return $back;
    }

    /** Pays with a card the client saved. Returns a client secret when their bank wants them to confirm it. */
    public function stripeSaved(Request $request, int $invoice, Stripe $stripe): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'integer']]);
        $invoice = $this->payable($request, $invoice);
        abort_unless($stripe->isEnabled(), 404);
        $method = $request->user('client')->paymentMethods()->where('gateway', $stripe->key())->findOrFail($data['payment_method']);

        try {
            $result = $stripe->payWithSaved($invoice, $method);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['status'] === 'requires_action') {
            return response()->json(['client_secret' => $result['client_secret']]);
        }

        $this->flashStripe($result['status'] === 'paid');

        return response()->json(['redirect' => route('portal.invoices.show', $invoice)]);
    }

    private function flashStripe(bool $paid): void
    {
        session()->flash('status', $paid
            ? __('Thank you, your payment was received.')
            : __('Thank you. Your payment is still processing, and this invoice will update when it clears.'));
    }

    /** The logged-in client's own invoice, if it still has a balance to pay. */
    private function payable(Request $request, int $id): Invoice
    {
        $invoice = $request->user('client')->invoices()->findOrFail($id);

        abort_unless($invoice->status === InvoiceStatus::Unpaid && $invoice->balance() > 0, 409, __('This invoice has nothing left to pay.'));

        return $invoice;
    }
}
