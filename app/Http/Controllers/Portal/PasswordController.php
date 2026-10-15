<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Security\Captcha;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function request(): View
    {
        return view('portal.auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Captcha::check($request, 'password');

        // Account holders first, then contacts who sign in with their own email.
        if (Password::broker('clients')->sendResetLink($request->only('email')) === Password::INVALID_USER) {
            Password::broker('contacts')->sendResetLink($request->only('email'));
        }

        // Same answer whether or not the email exists, so accounts can't be probed.
        return back()->with('status', 'If that email has an account, a link to set your password is on its way.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('portal.auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        $credentials = $request->only('email', 'password', 'password_confirmation', 'token');
        $status = Password::broker('clients')->reset($credentials, function (Client $client, string $password) {
            $client->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($client));
            Activity::record('Reset their portal password', $client);
        });

        if ($status === Password::INVALID_USER) {
            $status = Password::broker('contacts')->reset($credentials, function (ClientContact $contact, string $password) {
                $contact->forceFill(['password' => $password])->save();
                event(new PasswordReset($contact));
                Activity::record("Contact {$contact->fullName()} set their portal password", $contact->client);
            });
        }

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('portal.login')->with('status', 'Your password is set. You can log in now.');
    }
}
