<?php

namespace App\Http\Controllers\Admin;

use App\Hooks\Hooks;
use App\Http\Controllers\Controller;
use App\Integrations\Sms\Sms;
use App\Integrations\Sms\SmsNotifier;
use App\Integrations\Sms\SmsProvider;
use App\Models\Activity;
use App\Models\SmsLog;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Settings > SMS: the text message provider, and who is texted about what. */
class SmsSettingsController extends Controller
{
    public function __construct(private Sms $sms) {}

    public function edit(Hooks $hooks): View
    {
        return view('admin.sms.edit', [
            'providers' => $this->sms->providers(),
            'provider' => config('sms.provider'),
            'from' => config('sms.from'),
            'credentials' => $this->sms->credentials(),
            'countryCode' => config('sms.default_country_code'),
            'staffNumbers' => implode("\n", (array) config('sms.staff_numbers')),
            'staffEvents' => (array) config('sms.staff_events'),
            'clientEvents' => (array) config('sms.client_events'),
            'events' => $hooks->events(),
            'clientEventLabels' => SmsNotifier::CLIENT_EVENTS,
            'log' => SmsLog::with('client')->latest('id')->limit(30)->get(),
        ]);
    }

    public function update(Request $request, Hooks $hooks): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', Rule::in($this->sms->providers()->keys()->all())],
            'from' => ['nullable', 'string', 'max:40'],
            'default_country_code' => ['nullable', 'regex:/^\+?\d{1,4}$/'],
            'staff_numbers' => ['nullable', 'string', 'max:2000'],
            'staff_events' => ['array'],
            'staff_events.*' => [Rule::in(array_keys($hooks->events()))],
            'client_events' => ['array'],
            'client_events.*' => [Rule::in(array_keys(SmsNotifier::CLIENT_EVENTS))],
            'credentials' => ['array'],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ], [], ['from' => 'sending number', 'default_country_code' => 'country code']);

        $numbers = collect(preg_split('/[\r\n,]+/', (string) ($data['staff_numbers'] ?? '')))->map(fn ($n) => trim($n))->filter()->values();
        $bad = $numbers->reject(fn ($n) => Sms::normalise($n) !== null);
        if ($bad->isNotEmpty()) {
            throw ValidationException::withMessages(['staff_numbers' => 'These aren\'t phone numbers: '.$bad->join(', ').'. Write them with the country code, such as +15551234567.']);
        }

        Settings::save([
            'sms.provider' => $data['provider'] ?? null,
            'sms.from' => $data['from'] ?? null,
            'sms.credentials' => $this->credentials($data['provider'] ?? null, (array) ($data['credentials'] ?? [])),
            'sms.default_country_code' => ltrim((string) ($data['default_country_code'] ?? ''), '+'),
            'sms.staff_numbers' => $numbers->all(),
            'sms.staff_events' => array_values($data['staff_events'] ?? []),
            'sms.client_events' => array_values($data['client_events'] ?? []),
        ]);
        Activity::record('Changed the SMS settings');

        return redirect()->route('admin.sms.edit')->with('status', 'SMS settings saved.');
    }

    /** Queues a test text to one number. */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:40']], [], ['to' => 'number']);

        if (! $this->sms->configured()) {
            return back()->withErrors(['to' => 'Choose a provider and a sending number first.']);
        }

        $log = $this->sms->queue($data['to'], 'Test message from '.config('app.name').'.');

        return $log
            ? back()->with('status', "Test sent to {$log->to}. Its result is in the log below.")
            : back()->withErrors(['to' => 'That isn\'t a phone number. Write it with the country code, such as +15551234567.']);
    }

    /**
     * The provider's credentials as JSON for the encrypted setting. A blank
     * secret field keeps the saved value, since secrets aren't shown again.
     *
     * @param  array<string, string|null>  $input
     */
    private function credentials(?string $key, array $input): ?string
    {
        /** @var SmsProvider|null $provider */
        $provider = $key ? $this->sms->providers()->get($key) : null;

        if ($provider === null || $provider->fields() === []) {
            return null;
        }

        $saved = config('sms.provider') === $key ? $this->sms->credentials() : [];
        $values = [];
        foreach ($provider->fields() as $name => $field) {
            $value = trim((string) ($input[$name] ?? ''));
            $values[$name] = $value === '' && ($field['secret'] ?? false) ? ($saved[$name] ?? '') : $value;
        }

        return json_encode($values);
    }
}
