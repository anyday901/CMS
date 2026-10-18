<?php

namespace App\Http\Controllers\Admin;

use App\Billing\PaymentMethods;
use App\Billing\SavedMethodCharger;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Payments\PaymentFailed;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Staff charging a client's saved card, removing saved cards, and turning automatic charging on or off. */
class PaymentMethodController extends Controller
{
    public function charge(Request $request, Invoice $invoice, SavedMethodCharger $charger): RedirectResponse
    {
        $data = $request->validate(['payment_method' => ['required', 'integer']]);
        $method = $invoice->client->paymentMethods()->findOrFail($data['payment_method']);

        try {
            $attempt = $charger->charge($invoice, $method, PaymentAttempt::STAFF);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['charge' => $e->getMessage()]);
        }

        $amount = Money::format($attempt->amount, $attempt->currency);

        return match ($attempt->status) {
            PaymentAttempt::SUCCEEDED => back()->with('status', "Charged {$amount} to {$attempt->method_label}."),
            PaymentAttempt::PENDING => back()->with('status', "The charge of {$amount} is processing; the invoice will be marked paid when it clears."),
            default => back()->withErrors(['charge' => "The charge failed: {$attempt->message}"]),
        };
    }

    public function destroy(PaymentMethod $method, PaymentMethods $methods): RedirectResponse
    {
        try {
            $methods->remove($method);
        } catch (PaymentFailed $e) {
            return back()->withErrors(['payment_method' => $e->getMessage()]);
        }

        return back()->with('status', "Removed {$method->label()}.");
    }

    public function autoCharge(Request $request, Client $client): RedirectResponse
    {
        $request->validate(['auto_charge' => ['required', 'boolean']]);
        $client->forceFill(['auto_charge' => $request->boolean('auto_charge')])->save();
        Activity::record($client->auto_charge ? 'Turned automatic card payments on' : 'Turned automatic card payments off', $client);

        return back()->with('status', $client->auto_charge ? 'Automatic charging is on for this client.' : 'Automatic charging is off for this client.');
    }
}
