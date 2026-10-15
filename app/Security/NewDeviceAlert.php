<?php

namespace App\Security;

use App\Mail\ClientMailer;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\KnownDevice;
use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Emails a client or contact when their email signs in to the portal from a
 * browser it hasn't signed in from before. Each browser gets a random token
 * in a long-lived cookie; the first browser an email is seen on is just
 * noted, so nobody is emailed about their first login.
 */
class NewDeviceAlert
{
    public const COOKIE = 'portal_device';

    /** Five years. */
    private const MINUTES = 60 * 24 * 365 * 5;

    public function __construct(private ClientMailer $mailer) {}

    public function check(Request $request, Client|ClientContact $account): void
    {
        $email = LoginSecurity::normalize((string) $account->email);
        $token = (string) $request->cookies->get(self::COOKIE);
        $known = $token !== '' ? KnownDevice::where('email', $email)->where('token_hash', hash('sha256', $token))->first() : null;

        if ($known !== null) {
            $known->update(['last_used_at' => now(), 'ip_address' => $request->ip()]);

            return;
        }

        $firstDevice = KnownDevice::where('email', $email)->doesntExist();

        if (strlen($token) !== 40) {
            $token = Str::random(40);
        }
        Cookie::queue(self::COOKIE, $token, self::MINUTES);

        KnownDevice::create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
            'created_at' => now(),
        ]);

        if (! $firstDevice && config('security.new_device_emails')) {
            $this->email($request, $account);
        }
    }

    private function email(Request $request, Client|ClientContact $account): void
    {
        $values = [
            'login_time' => now()->format('M j, Y g:i A T'),
            'login_browser' => LoginAttempt::describeBrowser((string) $request->userAgent()),
            'login_ip' => $request->ip() ?? 'unknown',
            'password_url' => route('portal.password.request'),
        ];

        $account instanceof ClientContact
            ? $this->mailer->sendToContact($account, 'new_device_login', $values)
            : $this->mailer->send($account, 'new_device_login', $values);
    }
}
