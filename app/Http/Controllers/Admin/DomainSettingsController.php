<?php

namespace App\Http\Controllers\Admin;

use App\Domains\DomainName;
use App\Domains\RegistrarError;
use App\Domains\RegistrarRegistry;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Settings > Domains: registrar credentials, default nameservers, renewals and reminders. */
class DomainSettingsController extends Controller
{
    public function __construct(private RegistrarRegistry $registrars) {}

    public function edit(): View
    {
        return view('admin.domain-settings.edit', [
            'registrars' => $this->registrars->all(),
            'credentials' => $this->registrars->allCredentials(),
            'nameservers' => (array) config('domains.nameservers'),
            'invoiceDays' => (int) config('domains.invoice_days_before_expiry'),
            'reminderDays' => implode(', ', (array) config('domains.reminder_days')),
            'autoRenew' => (bool) config('domains.auto_renew_default'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nameservers' => ['array', 'max:5'],
            'nameservers.*' => ['nullable', 'string', 'max:253'],
            'invoice_days_before_expiry' => ['required', 'integer', 'between:0,365'],
            'reminder_days' => ['nullable', 'string', 'max:100', 'regex:/^[\d,\s]*$/'],
            'credentials' => ['array'],
            'credentials.*' => ['array'],
            'credentials.*.*' => ['nullable', 'string', 'max:500'],
        ], ['reminder_days.regex' => 'Write the reminder days as numbers separated by commas, such as 30, 7, 1.']);

        $nameservers = collect($data['nameservers'] ?? [])->map(fn ($ns) => strtolower(rtrim(trim((string) $ns), '.')))->filter()->values();
        $bad = $nameservers->reject(fn ($ns) => DomainName::valid($ns));
        if ($bad->isNotEmpty()) {
            throw ValidationException::withMessages(['nameservers' => "These aren't nameserver names: {$bad->join(', ')}."]);
        }

        Settings::save([
            'domains.credentials' => json_encode($this->credentials((array) ($data['credentials'] ?? []))),
            'domains.nameservers' => $nameservers->all(),
            'domains.invoice_days_before_expiry' => (int) $data['invoice_days_before_expiry'],
            'domains.reminder_days' => collect(explode(',', (string) ($data['reminder_days'] ?? '')))->map(fn ($d) => (int) trim($d))->filter(fn ($d) => $d > 0)->unique()->sortDesc()->values()->all(),
            'domains.auto_renew_default' => $request->boolean('auto_renew_default'),
        ]);
        Activity::record('Changed the domain settings');

        return redirect()->route('admin.domain-settings.edit')->with('status', 'Domain settings saved.');
    }

    /** Checks a registrar's saved credentials with one harmless call. */
    public function test(string $registrar): RedirectResponse
    {
        $module = $this->registrars->find($registrar) ?? abort(404);

        if (! $this->registrars->configured($registrar)) {
            return back()->withErrors(['registrar' => "Enter and save the {$module->label()} credentials first."]);
        }

        try {
            $module->testConnection($this->registrars->credentials($registrar));
        } catch (RegistrarError $e) {
            return back()->withErrors(['registrar' => $e->getMessage()]);
        }

        return back()->with('status', "{$module->label()} answered: the credentials work.");
    }

    /**
     * Every registrar's credentials. A blank secret keeps the saved one,
     * since secrets aren't shown again; unticked boxes are saved as off.
     *
     * @param  array<string, array<string, string|null>>  $input
     * @return array<string, array<string, string>>
     */
    private function credentials(array $input): array
    {
        $saved = $this->registrars->allCredentials();
        $values = [];

        foreach ($this->registrars->all() as $key => $registrar) {
            foreach ($registrar->configFields() as $name => $field) {
                $value = trim((string) ($input[$key][$name] ?? ''));
                $values[$key][$name] = $value === '' && ($field['secret'] ?? false) ? (string) ($saved[$key][$name] ?? '') : $value;
            }
        }

        return $values;
    }
}
