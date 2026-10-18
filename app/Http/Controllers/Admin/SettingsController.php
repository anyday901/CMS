<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Ticket;
use App\Support\Language;
use App\Support\Settings;
use App\Tickets\BusinessHours;
use App\Tickets\SpamFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('admin.settings.edit', [
            'taxInclusive' => (bool) config('billing.tax_inclusive'),
            'attachInvoicePdf' => (bool) config('billing.attach_invoice_pdf'),
            'holdNewOrders' => (bool) config('billing.hold_new_orders'),
            'termsUrl' => config('store.terms_url'),
            'bankTransferInstructions' => config('payments.bank_transfer.instructions'),
            'mailInInstructions' => config('payments.mail_in.instructions'),
            'activityRetentionDays' => config('activity.retention_days'),
            'closeInactiveHours' => config('tickets.close_inactive_hours'),
            'guestTickets' => (bool) config('tickets.guest_tickets'),
            'spam' => [
                'use_spam_headers' => (bool) config('tickets.spam.use_spam_headers'),
                'blocked_senders' => (array) config('tickets.spam.blocked_senders'),
                'blocked_subjects' => (array) config('tickets.spam.blocked_subjects'),
                'blocked_phrases' => (array) config('tickets.spam.blocked_phrases'),
                'new_tickets_per_hour' => config('tickets.spam.new_tickets_per_hour'),
            ],
            'mainLanguage' => Language::main(),
            'languages' => Language::others(),
            'businessHours' => BusinessHours::fromConfig(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_inclusive' => ['boolean'],
            'attach_invoice_pdf' => ['boolean'],
            'hold_new_orders' => ['boolean'],
            'terms_url' => ['nullable', 'url:http,https', 'max:255'],
            'bank_transfer_instructions' => ['nullable', 'string', 'max:5000'],
            'mail_in_instructions' => ['nullable', 'string', 'max:5000'],
            'activity_retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'close_inactive_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'hours' => ['array'],
            'hours.*.open' => ['boolean'],
            'hours.*.from' => ['nullable', 'date_format:H:i'],
            'hours.*.to' => ['nullable', 'date_format:H:i'],
            'holidays' => ['nullable', 'string', 'max:5000'],
            'timezone' => ['nullable', 'timezone:all'],
            'guest_tickets' => ['boolean'],
            'spam_use_headers' => ['boolean'],
            'spam_blocked_senders' => ['nullable', 'string', 'max:20000'],
            'spam_blocked_subjects' => ['nullable', 'string', 'max:20000'],
            'spam_blocked_phrases' => ['nullable', 'string', 'max:20000'],
            'spam_new_tickets_per_hour' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'main_language' => ['nullable', 'string', 'max:12'],
            'languages' => ['nullable', 'string', 'max:500'],
        ]);
        [$mainLanguage, $languages] = $this->languages($request);
        [$week, $holidays] = $this->businessHours($request);

        $hoursBefore = [config('tickets.business_hours'), config('tickets.holidays'), config('tickets.timezone')];
        Settings::save([
            'billing.tax_inclusive' => $request->boolean('tax_inclusive'),
            'billing.attach_invoice_pdf' => $request->boolean('attach_invoice_pdf'),
            'billing.hold_new_orders' => $request->boolean('hold_new_orders'),
            'store.terms_url' => ($data['terms_url'] ?? null) ?: null,
            'payments.bank_transfer.instructions' => trim((string) ($data['bank_transfer_instructions'] ?? '')) ?: null,
            'payments.mail_in.instructions' => trim((string) ($data['mail_in_instructions'] ?? '')) ?: null,
            'activity.retention_days' => filled($data['activity_retention_days'] ?? null) ? (int) $data['activity_retention_days'] : null,
            'tickets.close_inactive_hours' => filled($data['close_inactive_hours'] ?? null) ? (int) $data['close_inactive_hours'] : null,
            'tickets.business_hours' => $week,
            'tickets.holidays' => $holidays,
            'tickets.timezone' => $data['timezone'] ?? config('tickets.timezone'),
            'tickets.guest_tickets' => $request->boolean('guest_tickets'),
            'tickets.spam.use_spam_headers' => $request->boolean('spam_use_headers'),
            'tickets.spam.blocked_senders' => array_map('strtolower', SpamFilter::lines($data['spam_blocked_senders'] ?? '')),
            'tickets.spam.blocked_subjects' => SpamFilter::lines($data['spam_blocked_subjects'] ?? ''),
            'tickets.spam.blocked_phrases' => SpamFilter::lines($data['spam_blocked_phrases'] ?? ''),
            'tickets.spam.new_tickets_per_hour' => filled($data['spam_new_tickets_per_hour'] ?? null) ? (int) $data['spam_new_tickets_per_hour'] : null,
            'help.main_language' => $mainLanguage,
            'help.languages' => $languages,
        ]);

        if ($hoursBefore != [config('tickets.business_hours'), config('tickets.holidays'), config('tickets.timezone')]) {
            Ticket::refreshResponseDue(Ticket::whereHas('department', fn ($query) => $query->where('sla_business_hours', true)));
        }

        Activity::record('Updated settings', null, [
            'tax_inclusive' => (bool) config('billing.tax_inclusive'),
            'attach_invoice_pdf' => (bool) config('billing.attach_invoice_pdf'),
            'hold_new_orders' => (bool) config('billing.hold_new_orders'),
            'terms_url' => config('store.terms_url'),
            'bank_transfer_instructions' => filled(config('payments.bank_transfer.instructions')),
            'mail_in_instructions' => filled(config('payments.mail_in.instructions')),
            'activity_retention_days' => config('activity.retention_days'),
            'close_inactive_hours' => config('tickets.close_inactive_hours'),
            'business_hours' => config('tickets.business_hours'),
            'holidays' => config('tickets.holidays'),
            'timezone' => config('tickets.timezone'),
            'guest_tickets' => (bool) config('tickets.guest_tickets'),
            'spam' => config('tickets.spam'),
            'main_language' => config('help.main_language'),
            'languages' => config('help.languages'),
        ]);

        return redirect()->route('admin.settings.edit')->with('status', 'Settings saved.');
    }

    /**
     * The main language and the other languages from the form.
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws ValidationException when one isn't a language code
     */
    private function languages(Request $request): array
    {
        // Left out of the form entirely: keep what's saved.
        if (! $request->has('main_language')) {
            return [Language::main(), Language::others()];
        }

        $main = Language::normalize((string) $request->input('main_language'));
        $codes = preg_split('/[\s,]+/', (string) $request->input('languages'), -1, PREG_SPLIT_NO_EMPTY);
        $bad = collect($codes)->first(fn (string $code) => Language::normalize($code) === null);

        $errors = array_filter([
            'main_language' => $main === null ? 'Enter the main language as a code, such as en or pt-BR.' : null,
            'languages' => $bad !== null ? "\"{$bad}\" isn't a language code. Use codes such as fr, de or pt-BR." : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$main, array_values(array_diff(Language::list($codes), [$main]))];
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
