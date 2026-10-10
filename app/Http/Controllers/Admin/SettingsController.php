<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const PERCENT = 'percent';

    public function edit(): View
    {
        return view('admin.settings.edit', [
            'lateFeeDays' => config('billing.late_fee_after_days'),
            'lateFeeType' => config('billing.late_fee_type'),
            'lateFeeAmount' => config('billing.late_fee_amount'),
            'taxInclusive' => (bool) config('billing.tax_inclusive'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'late_fee_after_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'late_fee_type' => ['required', Rule::in(['fixed', self::PERCENT])],
            'late_fee_amount' => ['nullable', Money::rule()],
            'tax_inclusive' => ['boolean'],
        ]);

        $amount = Money::parse($data['late_fee_amount'] ?? '0');

        if ($data['late_fee_type'] === self::PERCENT && $amount > 10000) {
            throw ValidationException::withMessages(['late_fee_amount' => 'A percentage late fee cannot be over 100%.']);
        }

        if (filled($data['late_fee_after_days'] ?? null) && $amount <= 0) {
            throw ValidationException::withMessages(['late_fee_amount' => 'Enter a late fee amount, or leave the days blank to turn late fees off.']);
        }

        Settings::save([
            'billing.late_fee_after_days' => filled($data['late_fee_after_days'] ?? null) ? (int) $data['late_fee_after_days'] : null,
            'billing.late_fee_type' => $data['late_fee_type'],
            'billing.late_fee_amount' => Money::toInput($amount),
            'billing.tax_inclusive' => $request->boolean('tax_inclusive'),
        ]);

        Activity::record('Updated billing settings', null, [
            'late_fee_after_days' => config('billing.late_fee_after_days'),
            'late_fee_type' => config('billing.late_fee_type'),
            'late_fee_amount' => config('billing.late_fee_amount'),
            'tax_inclusive' => (bool) config('billing.tax_inclusive'),
        ]);

        return redirect()->route('admin.settings.edit')->with('status', 'Settings saved.');
    }
}
