<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Ordering\Checkout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Remembers the currency a guest picked in the store. A client who signs up
 * at checkout is billed in it; signed-in clients keep their account's.
 */
class CurrencyController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['currency' => ['required', 'string', Rule::in(Currency::codes())]]);

        if ($request->user('client') === null) {
            $request->session()->put(Checkout::SESSION_CURRENCY, $data['currency']);
        }

        return redirect()->back(fallback: route('store.index'));
    }
}
