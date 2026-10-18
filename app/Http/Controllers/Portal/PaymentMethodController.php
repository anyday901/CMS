<?php

namespace App\Http\Controllers\Portal;

use App\Billing\PaymentMethods;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Payments\GatewayRegistry;
use App\Payments\Gateways\Stripe;
use App\Payments\Gateways\StripeCards;
use App\Payments\PaymentFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The portal's Payment methods page: saved cards, the default one, and automatic charging. */
class PaymentMethodController extends Controller
{
    public function index(Request $request, GatewayRegistry $gateways): View
    {
        $client = $request->user('client');

        return view('portal.payment-methods.index', [
            'client' => $client,
            'methods' => $client->paymentMethods()->get(),
            'gateways' => $gateways->savingMethods(),
        ]);
    }

    public function autoCharge(Request $request): RedirectResponse
    {
        $request->validate(['auto_charge' => ['required', 'boolean']]);
        $client = $request->user('client');
        $client->forceFill(['auto_charge' => $request->boolean('auto_charge')])->save();
        Activity::record($client->auto_charge ? 'Turned automatic card payments on' : 'Turned automatic card payments off', $client);

        return back()->with('status', $client->auto_charge
            ? __('Invoices will be charged to your default card when they are due.')
            : __('Invoices will no longer be charged automatically. Pay each one from its invoice page.'));
    }

    public function makeDefault(Request $request, int $method, PaymentMethods $methods): RedirectResponse
    {
        $method = $request->user('client')->paymentMethods()->findOrFail($method);
        $methods->makeDefault($method);

        return back()->with('status', __(':method is now your default payment method.', ['method' => $method->label()]));
    }

    public function destroy(Request $request, int $method, PaymentMethods $methods): RedirectResponse
    {
        $method = $request->user('client')->paymentMethods()->findOrFail($method);

        try {
            $methods->remove($method);
        } catch (PaymentFailed $e) {
            return back()->withErrors(['payment_method' => $e->getMessage()]);
        }

        return back()->with('status', __(':method was removed.', ['method' => $method->label()]));
    }

    /** Starts adding a card with Stripe. */
    public function stripeSetup(Request $request, Stripe $stripe, StripeCards $cards): JsonResponse
    {
        abort_unless($stripe->isEnabled(), 404);

        try {
            return response()->json(['client_secret' => $cards->createSetupIntent($request->user('client'))]);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Saves the card Stripe.js confirmed in the browser. */
    public function stripeConfirm(Request $request, Stripe $stripe, StripeCards $cards): JsonResponse
    {
        $data = $request->validate(['setup_intent' => ['required', 'string', 'max:255', 'regex:/^seti_[A-Za-z0-9]+$/']]);
        abort_unless($stripe->isEnabled(), 404);

        try {
            $method = $cards->confirmSetup($request->user('client'), $data['setup_intent']);
        } catch (PaymentFailed $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        Activity::record("Saved the card {$method->label()}", $method->client);
        session()->flash('status', __(':method was saved.', ['method' => $method->label()]));

        return response()->json(['redirect' => route('portal.payment-methods.index')]);
    }

    /** Where Stripe sends the client back when saving a card needed a redirect. */
    public function stripeReturn(Request $request, Stripe $stripe, StripeCards $cards): RedirectResponse
    {
        $intent = (string) $request->query('setup_intent');
        $back = redirect()->route('portal.payment-methods.index');

        if (! $stripe->isEnabled() || ! preg_match('/^seti_[A-Za-z0-9]+$/', $intent)) {
            return $back;
        }

        try {
            $method = $cards->confirmSetup($request->user('client'), $intent);
        } catch (PaymentFailed $e) {
            return $back->withErrors(['payment_method' => $e->getMessage()]);
        }

        return $back->with('status', __(':method was saved.', ['method' => $method->label()]));
    }
}
