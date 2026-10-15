<?php

namespace App\Security;

use App\Mail\ClientMailer;
use App\Models\Activity;
use App\Models\Client;
use Illuminate\Support\Facades\URL;

/**
 * Confirms a client's email address is theirs: a signed link, emailed when
 * they sign up or change their address. The link names the address it was
 * sent to, so it stops working when the address changes again.
 */
class EmailVerification
{
    public const DAYS = 3;

    public function __construct(private ClientMailer $mailer) {}

    public function send(Client $client): void
    {
        if ($client->hasVerifiedEmail() || blank($client->email)) {
            return;
        }

        $this->mailer->send($client, 'email_verification', [
            'verify_url' => self::url($client),
            'expire_days' => self::DAYS,
        ]);
    }

    public static function url(Client $client): string
    {
        return URL::temporarySignedRoute('portal.verification.verify', now()->addDays(self::DAYS), [
            'client' => $client->id,
            'hash' => self::hash($client),
        ]);
    }

    public static function hash(Client $client): string
    {
        return sha1(strtolower((string) $client->email));
    }

    /** Marks the address verified when the link is for the address the client has now. */
    public function verify(Client $client, string $hash): bool
    {
        if (! hash_equals(self::hash($client), $hash)) {
            return false;
        }

        if (! $client->hasVerifiedEmail()) {
            $client->forceFill(['email_verified_at' => now()])->save();
            Activity::record("Verified their email address {$client->email}", $client);
        }

        return true;
    }
}
