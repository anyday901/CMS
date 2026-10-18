<?php

namespace App\Http\Controllers\Portal;

use App\Billing\AddFunds;
use App\Http\Controllers\Controller;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/** Prepaying into account credit: the amount goes on an add funds invoice to pay like any other. */
class AddFundsController extends Controller
{
    public function create(Request $request, AddFunds $funds): View
    {
        abort_unless(AddFunds::enabled(), 404);
        $client = $request->user('client');

        return view('portal.add-funds', [
            'client' => $client,
            'limits' => $funds->limits($client),
            'available' => $funds->available($client),
        ]);
    }

    public function store(Request $request, AddFunds $funds): RedirectResponse
    {
        abort_unless(AddFunds::enabled(), 404);
        $data = $request->validate(['amount' => ['required', 'string', 'max:20']], ['amount.*' => __('Enter an amount like 25.00.')]);

        try {
            $amount = Money::parse($data['amount']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['amount' => __('Enter an amount like 25.00.')]);
        }

        try {
            $invoice = $funds->create($request->user('client'), $amount);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return redirect()->route('portal.invoices.show', $invoice)
            ->with('status', __('Pay this invoice and the amount is added to your account credit.'));
    }
}
