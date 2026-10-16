<?php

namespace App\Http\Controllers\Store;

use App\Domains\DomainLookup;
use App\Domains\DomainName;
use App\Domains\DomainOrders;
use App\Domains\Whois;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Tld;
use App\Ordering\Cart;
use App\Ordering\Checkout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** The store's domain search, price list and WHOIS lookup, and adding domains to the cart. */
class DomainController extends Controller
{
    public function __construct(private Cart $cart, private DomainOrders $orders) {}

    public function index(Request $request, DomainLookup $lookup): View
    {
        $currency = Checkout::currencyFor($request->user('client'));
        $search = trim((string) $request->query('domain', ''));

        return view('store.domains', [
            'search' => $search,
            'lookup' => $search !== '' ? $lookup->search(mb_substr($search, 0, 253)) : null,
            'tlds' => Tld::sold()->with('prices')->get()->filter(fn (Tld $tld) => $tld->fromPrice($currency, 'register') || $tld->fromPrice($currency, 'transfer')),
            'currency' => $currency,
            'transfer' => $request->query('type') === Domain::TRANSFER,
        ]);
    }

    public function whois(Request $request, Whois $whois): View
    {
        $search = trim((string) $request->query('domain', ''));
        $name = $search !== '' ? DomainName::normalise(mb_substr($search, 0, 253)) : null;

        return view('store.whois', [
            'search' => $search,
            'result' => $name !== null ? $whois->lookup($name, Tld::where('extension', substr($name, strpos($name, '.') + 1))->first()) : null,
            'invalid' => $search !== '' && $name === null,
        ]);
    }

    /** Adds a registration or a transfer to the cart. */
    public function add(Request $request, DomainLookup $lookup): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:253'],
            'type' => ['required', Rule::in([Domain::REGISTER, Domain::TRANSFER])],
            'years' => ['required', 'integer', 'between:1,10'],
            'id_protection' => ['boolean'],
            'epp_code' => ['nullable', 'string', 'max:100'],
        ]);

        if ($this->cart->isFull()) {
            throw ValidationException::withMessages(['name' => __('Your cart is full. Check out or remove something first.')]);
        }

        $line = ['name' => $data['name'], 'type' => $data['type'], 'years' => (int) $data['years'], 'id_protection' => (bool) ($data['id_protection'] ?? false), 'epp_code' => $data['epp_code'] ?? null];

        try {
            $priced = $this->orders->line($line, Checkout::currencyFor($request->user('client')));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        $available = $lookup->isAvailable($priced->name, $priced->tld);
        if ($priced->type === Domain::REGISTER && $available === false) {
            throw ValidationException::withMessages(['name' => __(':name is already taken. If it is yours, transfer it to us instead.', ['name' => $priced->name])]);
        }
        if ($priced->type === Domain::TRANSFER && $available === true) {
            throw ValidationException::withMessages(['name' => __(":name isn't registered yet, so it can't be transferred. Register it instead.", ['name' => $priced->name])]);
        }

        $this->cart->addDomain(['name' => $priced->name] + $line);

        return redirect()->route('cart.index')->with('status', __(':domain is in your cart.', ['domain' => $priced->name]));
    }
}
