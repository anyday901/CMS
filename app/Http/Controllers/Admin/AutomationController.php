<?php

namespace App\Http\Controllers\Admin;

use App\Console\Scheduler;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Every number of days the nightly billing run works by, and when it runs, in one place. */
class AutomationController extends Controller
{
    private const PERCENT = 'percent';

    private const DAYS = ['nullable', 'integer', 'min:0', 'max:365'];

    public function edit(): View
    {
        return view('admin.automation.edit', [
            'runTime' => Scheduler::billingRunTime(),
            'invoiceDays' => config('billing.invoice_days_before_due'),
            'applyCredit' => (bool) config('billing.apply_credit_automatically'),
            'reminderDays' => implode(', ', (array) config('billing.reminder_days')),
            'lateFeeDays' => config('billing.late_fee_after_days'),
            'lateFeeType' => config('billing.late_fee_type'),
            'lateFeeAmount' => config('billing.late_fee_amount'),
            'suspendDays' => config('billing.suspend_after_days'),
            'terminateDays' => config('billing.terminate_after_days'),
            'overageDays' => config('billing.overage_due_days'),
            'updateRates' => (bool) config('billing.update_exchange_rates'),
            'timezone' => config('app.timezone'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'run_time' => ['required', 'date_format:H:i'],
            'invoice_days_before_due' => ['required', 'integer', 'min:0', 'max:365'],
            'apply_credit_automatically' => ['boolean'],
            'reminder_days' => ['nullable', 'string', 'max:200'],
            'late_fee_after_days' => self::DAYS,
            'late_fee_type' => ['required', Rule::in(['fixed', self::PERCENT])],
            'late_fee_amount' => ['nullable', Money::rule()],
            'suspend_after_days' => self::DAYS,
            'terminate_after_days' => self::DAYS,
            'overage_due_days' => ['required', 'integer', 'min:0', 'max:365'],
            'update_exchange_rates' => ['boolean'],
        ]);

        $amount = Money::parse($data['late_fee_amount'] ?? '0');
        $days = fn (string $key) => filled($data[$key] ?? null) ? (int) $data[$key] : null;
        $reminders = $this->reminderDays((string) ($data['reminder_days'] ?? ''));

        $errors = array_filter([
            'late_fee_amount' => match (true) {
                $data['late_fee_type'] === self::PERCENT && $amount > 10000 => 'A percentage late fee cannot be over 100%.',
                $days('late_fee_after_days') !== null && $amount <= 0 => 'Enter a late fee amount, or leave the days blank to turn late fees off.',
                default => null,
            },
            'terminate_after_days' => $days('terminate_after_days') !== null && $days('suspend_after_days') !== null && $days('terminate_after_days') < $days('suspend_after_days')
                ? 'Services can\'t be terminated before they are suspended. Make termination the same number of days or more.'
                : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $values = [
            'billing.run_time' => $data['run_time'],
            'billing.invoice_days_before_due' => (int) $data['invoice_days_before_due'],
            'billing.apply_credit_automatically' => $request->boolean('apply_credit_automatically'),
            'billing.reminder_days' => $reminders,
            'billing.late_fee_after_days' => $days('late_fee_after_days'),
            'billing.late_fee_type' => $data['late_fee_type'],
            'billing.late_fee_amount' => Money::toInput($amount),
            'billing.suspend_after_days' => $days('suspend_after_days'),
            'billing.terminate_after_days' => $days('terminate_after_days'),
            'billing.overage_due_days' => (int) $data['overage_due_days'],
            'billing.update_exchange_rates' => $request->boolean('update_exchange_rates'),
        ];
        Settings::save($values);
        Activity::record('Updated automation settings', null, collect($values)->mapWithKeys(fn ($value, $key) => [str_replace('billing.', '', $key) => $value])->all());

        return redirect()->route('admin.automation.edit')->with('status', 'Automation settings saved.');
    }

    /**
     * "1, 7, 14" as a sorted list of days.
     *
     * @return list<int>
     *
     * @throws ValidationException when one isn't a whole number of days
     */
    private function reminderDays(string $input): array
    {
        $days = preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY);
        $bad = collect($days)->first(fn (string $day) => ! ctype_digit($day) || (int) $day < 1 || (int) $day > 365);

        if ($bad !== null) {
            throw ValidationException::withMessages(['reminder_days' => "\"{$bad}\" isn't a number of days from 1 to 365."]);
        }

        return collect($days)->map(fn (string $day) => (int) $day)->unique()->sort()->values()->all();
    }
}
