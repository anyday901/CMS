<?php

namespace App\Security;

use App\Mail\ClientMailer;
use App\Models\ClientContact;
use Illuminate\Support\Facades\Password;

/**
 * Emails a contact a link to choose their portal password, or, when they
 * already sign in to another account with that email, that they can now
 * reach this one with the same login.
 */
class ContactInvitation
{
    public const INVITED = 'invited';

    public const ADDED = 'added';

    /** What each permission lets the contact do, for the invitation. */
    private const DOING = [
        'billing' => 'see and pay invoices',
        'orders' => 'place orders',
        'services' => 'manage services',
        'support' => 'open support tickets',
    ];

    public function __construct(private ClientMailer $mailer) {}

    /**
     * Tells a contact who was just given portal access, or a new login email:
     * an invitation when
     * they have no password yet, or a note that their login now reaches
     * this account. Returns which was sent, or null.
     */
    public function sendIfNew(ClientContact $contact, bool $accessJustGiven): ?string
    {
        if (! $contact->portal_access || ! ($accessJustGiven || $contact->joinedLogin)) {
            return null;
        }

        if ($contact->joinedLogin) {
            $this->mailer->sendToContact($contact, 'contact_account_added', [
                'contact_permissions' => $this->permissions($contact),
                'login_url' => route('portal.login'),
            ]);

            return self::ADDED;
        }

        if ($contact->password !== null) {
            return null;
        }

        $this->send($contact);

        return self::INVITED;
    }

    /** The status message for staff or the account holder after saving a contact. */
    public static function message(?string $sent, string $saved, ClientContact $contact): string
    {
        return match ($sent) {
            self::INVITED => "{$saved} We've emailed {$contact->email} a link to choose their password.",
            self::ADDED => "{$saved} {$contact->first_name} already signs in to another account with {$contact->email}, so they use the same login here and we've let them know.",
            default => $saved,
        };
    }

    public function send(ClientContact $contact): void
    {
        $token = Password::broker('contacts')->createToken($contact);

        $this->mailer->sendToContact($contact, 'contact_invite', [
            'contact_permissions' => $this->permissions($contact),
            'reset_url' => route('portal.password.reset', ['token' => $token, 'email' => $contact->email]),
            'expire_hours' => intdiv((int) config('auth.passwords.contacts.expire'), 60),
        ]);
    }

    private function permissions(ClientContact $contact): string
    {
        $allowed = array_values(array_intersect_key(self::DOING, array_flip($contact->permissions ?? [])));

        return $allowed === [] ? 'sign in to the portal' : self::sentence($allowed);
    }

    /** @param  list<string>  $items */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' and '.$last;
    }
}
