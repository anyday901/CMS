<?php

namespace App\Http\Controllers\Concerns;

use App\Auth\Totp;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Setting up two-factor authentication on your own login: scan a QR code,
 * type the first code to turn it on, keep the recovery codes. Turning it
 * off or making new recovery codes takes your password.
 */
trait ManagesTwoFactor
{
    private const SETUP_KEY = 'two_factor_setup';

    /** The client, contact or staff member signed in. */
    abstract protected function twoFactorAccount(Request $request): Model;

    /** Where the two-factor settings are. */
    abstract protected function twoFactorPage(): string;

    /** Who the activity log entries are about. */
    protected function twoFactorSubject(Request $request): ?Model
    {
        return $this->twoFactorAccount($request);
    }

    public function startTwoFactor(Request $request): RedirectResponse
    {
        $request->session()->put(self::SETUP_KEY, Totp::secret());

        return redirect()->to($this->twoFactorPage().'#two-factor');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $secret = $request->session()->get(self::SETUP_KEY);

        if ($secret === null) {
            return redirect()->to($this->twoFactorPage().'#two-factor')->withErrors(['code' => 'Start again: scan the new QR code, then type the code it shows.']);
        }

        $codes = $this->twoFactorAccount($request)->enableTwoFactor($secret, $request->input('code'));

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Type the six digits the app shows now.']);
        }

        $request->session()->forget(self::SETUP_KEY);
        Activity::record('Turned on two-factor authentication', $this->twoFactorSubject($request));

        return redirect()->to($this->twoFactorPage().'#two-factor')
            ->with('status', 'Two-factor authentication is on. Keep your recovery codes somewhere safe.')
            ->with('recovery_codes', $codes);
    }

    public function cancelTwoFactorSetup(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SETUP_KEY);

        return redirect()->to($this->twoFactorPage().'#two-factor');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);
        $this->twoFactorAccount($request)->disableTwoFactor();
        Activity::record('Turned off two-factor authentication', $this->twoFactorSubject($request));

        return redirect()->to($this->twoFactorPage().'#two-factor')->with('status', 'Two-factor authentication is off.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $this->confirmPassword($request);
        $codes = $this->twoFactorAccount($request)->regenerateRecoveryCodes();
        Activity::record('Made new two-factor recovery codes', $this->twoFactorSubject($request));

        return redirect()->to($this->twoFactorPage().'#two-factor')
            ->with('status', 'Here are your new recovery codes. The old ones no longer work.')
            ->with('recovery_codes', $codes);
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->input('current_password'), (string) $this->twoFactorAccount($request)->password)) {
            throw ValidationException::withMessages(['current_password' => 'That isn\'t your current password.']);
        }
    }
}
