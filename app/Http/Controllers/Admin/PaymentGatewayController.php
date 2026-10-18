<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Payments\Gateways\CashAppPay;
use App\Payments\Gateways\PayPal;
use App\Payments\Gateways\Stripe;
use App\Support\Demo;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings > Payment gateways: Stripe's keys, and whether the other gateways are set up. */
class PaymentGatewayController extends Controller
{
    /** Secret fields: a blank one keeps what's saved. */
    private const SECRETS = ['secret_key', 'webhook_secret'];

    public function edit(Stripe $stripe, PayPal $paypal, CashAppPay $cashApp): View
    {
        return view('admin.payment-gateways.edit', [
            'stripe' => $stripe,
            'publishableKey' => config('payments.stripe.publishable_key'),
            'hasSecret' => ['secret_key' => filled(config('payments.stripe.secret_key')), 'webhook_secret' => filled(config('payments.stripe.webhook_secret'))],
            'others' => [
                ['label' => 'PayPal and Venmo', 'enabled' => $paypal->isEnabled(), 'env' => 'PAYPAL_CLIENT_ID, PAYPAL_SECRET, PAYPAL_WEBHOOK_ID'],
                ['label' => 'Cash App Pay (Square)', 'enabled' => $cashApp->isEnabled(), 'env' => 'SQUARE_APPLICATION_ID, SQUARE_ACCESS_TOKEN, SQUARE_LOCATION_ID'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Demo::refuse();

        $data = $request->validate([
            'publishable_key' => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
            'secret_key' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'forget_secret_key' => ['boolean'],
            'forget_webhook_secret' => ['boolean'],
        ], [
            'publishable_key.regex' => 'The publishable key starts with pk_test_ or pk_live_.',
            'secret_key.regex' => 'The secret key starts with sk_test_ or sk_live_ (or rk_ for a restricted key).',
            'webhook_secret.regex' => 'The webhook signing secret starts with whsec_.',
        ]);

        $values = ['payments.stripe.publishable_key' => $data['publishable_key'] ?? null];

        foreach (self::SECRETS as $field) {
            if ($request->boolean("forget_{$field}") || filled($data[$field] ?? null)) {
                $values["payments.stripe.{$field}"] = $request->boolean("forget_{$field}") ? null : $data[$field];
            }
        }

        Settings::save($values);
        Activity::record('Changed the Stripe settings', null, [
            'publishable_key' => $values['payments.stripe.publishable_key'],
            'changed_secrets' => array_values(array_filter(self::SECRETS, fn (string $field) => array_key_exists("payments.stripe.{$field}", $values))),
        ]);

        return redirect()->route('admin.payment-gateways.edit')->with('status', 'Payment gateway settings saved.');
    }
}
