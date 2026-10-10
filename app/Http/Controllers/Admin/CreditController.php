<?php

namespace App\Http\Controllers\Admin;

use App\Billing\CreditLedger;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreditController extends Controller
{
    public function store(Request $request, Client $client, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['add', 'remove'])],
            'amount' => ['required', Money::rule()],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $amount = Money::parse($data['amount']);

        try {
            $ledger->adjust($client, $data['direction'] === 'add' ? $amount : -$amount, $data['reason']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('status', 'Credit balance updated.');
    }
}
