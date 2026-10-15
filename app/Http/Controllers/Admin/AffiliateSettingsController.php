<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Affiliate;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The affiliate program's settings: on or off, the commission, when it's paid, clearing days, minimum payout and the cookie. */
class AffiliateSettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.affiliates.settings', [
            'enabled' => (bool) config('affiliates.enabled'),
            'selfSignup' => (bool) config('affiliates.self_signup'),
            'commissionType' => config('affiliates.commission_type'),
            'commissionAmount' => config('affiliates.commission_amount'),
            'firstInvoiceOnly' => (bool) config('affiliates.first_invoice_only'),
            'months' => config('affiliates.months'),
            'clearingDays' => config('affiliates.clearing_days'),
            'minimumPayout' => config('affiliates.minimum_payout'),
            'cookieDays' => config('affiliates.cookie_days'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'self_signup' => ['boolean'],
            'commission_type' => ['required', Rule::in([Affiliate::PERCENT, Affiliate::FIXED])],
            'commission_amount' => ['required', Money::rule()],
            'first_invoice_only' => ['boolean'],
            'months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'clearing_days' => ['required', 'integer', 'min:0', 'max:365'],
            'minimum_payout' => ['required', Money::rule()],
            'cookie_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $amount = Money::parse($data['commission_amount']);
        if ($data['commission_type'] === Affiliate::PERCENT && $amount > 10000) {
            throw ValidationException::withMessages(['commission_amount' => 'A percentage commission cannot be over 100%.']);
        }

        $values = [
            'affiliates.enabled' => $request->boolean('enabled'),
            'affiliates.self_signup' => $request->boolean('self_signup'),
            'affiliates.commission_type' => $data['commission_type'],
            'affiliates.commission_amount' => Money::toInput($amount),
            'affiliates.first_invoice_only' => $request->boolean('first_invoice_only'),
            'affiliates.months' => filled($data['months'] ?? null) ? (int) $data['months'] : null,
            'affiliates.clearing_days' => (int) $data['clearing_days'],
            'affiliates.minimum_payout' => Money::toInput(Money::parse($data['minimum_payout'])),
            'affiliates.cookie_days' => (int) $data['cookie_days'],
        ];
        Settings::save($values);
        Activity::record('Updated affiliate settings', null, collect($values)->mapWithKeys(fn ($value, $key) => [str_replace('affiliates.', '', $key) => $value])->all());

        return redirect()->route('admin.affiliates.settings')->with('status', 'Affiliate settings saved.');
    }
}
