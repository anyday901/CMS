<?php

namespace App\Http\Controllers\Store;

use App\Auth\PortalContact;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CustomField;
use App\Ordering\Cart;
use App\Ordering\Checkout;
use App\Security\Captcha;
use App\Security\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use InvalidArgumentException;

class CheckoutController extends Controller
{
    public function __construct(private Cart $cart, private Checkout $checkout, private EmailVerification $verification) {}

    public function show(Request $request): View|RedirectResponse
    {
        $quote = $this->checkout->quote($this->cart, $request->user('client'));

        if ($quote->rows === [] || $quote->errors !== []) {
            return redirect()->route('cart.index');
        }

        return view('store.checkout', [
            'quote' => $quote,
            'fields' => CustomField::forClients()->where('admin_only', false)->get(),
        ]);
    }

    /** Why this cart can't be checked out now, or null when it can. */
    private function cannotCheckOut(?Client $client): ?string
    {
        if ($client !== null && ! PortalContact::allows('orders')) {
            return 'Your login doesn\'t include placing orders. Ask the account holder if you need it.';
        }

        $quote = $this->checkout->quote($this->cart, $client);

        if ($quote->rows === [] || $quote->errors !== [] || $quote->promotionError !== null) {
            return $quote->promotionError ?? 'Check your cart and try again.';
        }

        return null;
    }

    /** Signs a new client up first when nobody is logged in, then places the order. */
    public function store(Request $request): RedirectResponse
    {
        $client = $request->user('client');
        $problem = $this->cannotCheckOut($client);

        if ($problem !== null) {
            return redirect()->route('cart.index')->withErrors(['cart' => $problem]);
        }

        $client ??= $this->signUp($request);

        try {
            ['order' => $order] = $this->checkout->place($this->cart, $client, $request->ip());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('cart.index')->withErrors(['cart' => $e->getMessage()]);
        }

        $invoice = $order->invoice;

        return $invoice->status === InvoiceStatus::Unpaid
            ? redirect()->route('portal.invoices.show', $invoice)->with('status', 'Thanks for your order. Pay this invoice to get started.')
            : redirect()->route('portal.services.index')->with('status', 'Thanks for your order. Nothing is due, so your services are being set up.');
    }

    /** Sends a client who already has an account to log in, then back here. */
    public function login(): RedirectResponse
    {
        redirect()->setIntendedUrl(route('checkout.show'));

        return redirect()->route('portal.login');
    }

    private function signUp(Request $request): Client
    {
        Captcha::check($request, 'register');
        $fields = CustomField::forClients()->where('admin_only', false)->get();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:clients,email', ClientContact::loginEmailRule()],
            'password' => ['required', 'confirmed', Password::min(10)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address1' => ['required', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
        ] + CustomField::rules($fields), [
            'email.unique' => 'There is already an account with this email. Log in to order with it.',
        ], CustomField::attributeNames($fields));

        $client = new Client([
            ...collect($data)->except('password')->all(),
            'country' => strtoupper($data['country']),
            'password' => $data['password'],
            'custom_fields' => CustomField::merge($fields, (array) $request->input('custom_fields', []), null),
        ]);
        $client->setMarketingConsent($request->boolean('marketing_emails'));
        $client->save();

        Auth::guard('client')->login($client);
        $request->session()->regenerate();
        Activity::record('Signed up at checkout', $client);
        if ($client->marketing_emails) {
            Activity::record('Agreed to marketing emails at sign-up', $client);
        }
        $this->verification->send($client);

        return $client;
    }
}
