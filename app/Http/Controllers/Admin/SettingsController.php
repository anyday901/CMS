<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Ticket;
use App\Support\Money;
use App\Support\Settings;
use App\Tickets\BusinessHours;
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
            'holdNewOrders' => (bool) config('billing.hold_new_orders'),
            'activityRetentionDays' => config('activity.retention_days'),
            'closeInactiveHours' => config('tickets.close_inactive_hours'),
            'businessHours' => BusinessHours::fromConfig(),
            'timezones' => timezone_identifiers_list(),
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
            'hold_new_orders' => ['boolean'],
            'activity_retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'close_inactive_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'hours' => ['array'],
            'hours.*.open' => ['boolean'],
            'hours.*.from' => ['nullable', 'date_format:H:i'],
            'hours.*.to' => ['nullable', 'date_format:H:i'],
            'holidays' => ['nullable', 'string', 'max:5000'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);
        [$week, $holidays] = $this->businessHours($request);

        $hoursBefore = [config('tickets.business_hours'), config('tickets.holidays'), config('tickets.timezone')];
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
            'billing.hold_new_orders' => $request->boolean('hold_new_orders'),
            'activity.retention_days' => filled($data['activity_retention_days'] ?? null) ? (int) $data['activity_retention_days'] : null,
            'tickets.close_inactive_hours' => filled($data['close_inactive_hours'] ?? null) ? (int) $data['close_inactive_hours'] : null,
            'tickets.business_hours' => $week,
            'tickets.holidays' => $holidays,
            'tickets.timezone' => $data['timezone'] ?? config('tickets.timezone'),
        ]);

        if ($hoursBefore != [config('tickets.business_hours'), config('tickets.holidays'), config('tickets.timezone')]) {
            Ticket::refreshResponseDue(Ticket::whereHas('department', fn ($query) => $query->where('sla_business_hours', true)));
        }

        Activity::record('Updated settings', null, [
            'late_fee_after_days' => config('billing.late_fee_after_days'),
            'late_fee_type' => config('billing.late_fee_type'),
            'late_fee_amount' => config('billing.late_fee_amount'),
            'tax_inclusive' => (bool) config('billing.tax_inclusive'),
            'attach_invoice_pdf' => (bool) config('billing.attach_invoice_pdf'),
            'hold_new_orders' => (bool) config('billing.hold_new_orders'),
            'activity_retention_days' => config('activity.retention_days'),
            'close_inactive_hours' => config('tickets.close_inactive_hours'),
            'business_hours' => config('tickets.business_hours'),
            'holidays' => config('tickets.holidays'),
            'timezone' => config('tickets.timezone'),
        ]);

        return redirect()->route('admin.settings.edit')->with('status', 'Settings saved.');
    }

    /**
     * The opening hours by day and the closed dates from the form.
     *
     * @return array{0: array<string, array{0: string, 1: string}>, 1: list<string>}
     *
     * @throws ValidationException when a day closes before it opens or a date is wrong
     */
    private function businessHours(Request $request): array
    {
        // Left out of the form entirely: keep what's saved.
        if (! $request->has('hours')) {
            return [(array) config('tickets.business_hours'), (array) config('tickets.holidays')];
        }

        $week = [];
        $errors = [];

        foreach (BusinessHours::DAYS as $day => $name) {
            $hours = (array) $request->input("hours.{$day}", []);

            if (! filter_var($hours['open'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $from = (string) ($hours['from'] ?? '');
            $to = (string) ($hours['to'] ?? '');

            if ($from === '' || $to === '' || $from >= $to) {
                $errors["hours.{$day}.to"] = "{$name} has to close after it opens.";

                continue;
            }

            $week[$day] = [$from, $to];
        }

        $holidays = collect(preg_split('/[\s,]+/', (string) $request->input('holidays'), -1, PREG_SPLIT_NO_EMPTY))->unique()->sort()->values();
        $bad = $holidays->first(fn (string $date) => ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)));

        if ($bad !== null) {
            $errors['holidays'] = "\"{$bad}\" isn't a date. Write each one as YYYY-MM-DD.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$week, $holidays->all()];
    }
}
