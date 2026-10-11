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
            'attachInvoicePdf' => (bool) config('billing.attach_invoice_pdf'),
            'activityRetentionDays' => config('activity.retention_days'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'late_fee_after_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'late_fee_type' => ['required', Rule::in(['fixed', self::PERCENT])],
            'late_fee_amount' => ['nullable', Money::rule()],
            'tax_inclusive' => ['boolean'],
            'attach_invoice_pdf' => ['boolean'],
            'activity_retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
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
            'billing.attach_invoice_pdf' => $request->boolean('attach_invoice_pdf'),
            'activity.retention_days' => filled($data['activity_retention_days'] ?? null) ? (int) $data['activity_retention_days'] : null,
        ]);

        Activity::record('Updated settings', null, [
            'late_fee_after_days' => config('billing.late_fee_after_days'),
            'late_fee_type' => config('billing.late_fee_type'),
            'late_fee_amount' => config('billing.late_fee_amount'),
            'tax_inclusive' => (bool) config('billing.tax_inclusive'),
            'attach_invoice_pdf' => (bool) config('billing.attach_invoice_pdf'),
            'activity_retention_days' => config('activity.retention_days'),
        ]);

        return redirect()->route('admin.settings.edit')->with('status', 'Settings saved.');
    }
}
