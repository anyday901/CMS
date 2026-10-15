<?php

namespace App\Security;

use App\Mail\ClientMailer;
use App\Models\ClientContact;
use Illuminate\Support\Facades\Password;

/** Emails a contact a link to choose their portal password. */
class ContactInvitation
{
    /** What each permission lets the contact do, for the invitation. */
    private const DOING = [
        'billing' => 'see and pay invoices',
        'orders' => 'place orders',
        'services' => 'manage services',
        'support' => 'open support tickets',
    ];

    public function __construct(private ClientMailer $mailer) {}

    /** Invites a contact who was just given portal access and has no password yet. */
    public function sendIfNew(ClientContact $contact, bool $accessJustGiven): bool
    {
        if (! $accessJustGiven || ! $contact->portal_access || $contact->password !== null) {
            return false;
        }

        $this->send($contact);

        return true;
    }

    public function send(ClientContact $contact): void
    {
        $token = Password::broker('contacts')->createToken($contact);
        $allowed = array_values(array_intersect_key(self::DOING, array_flip($contact->permissions ?? [])));

        $this->mailer->sendToContact($contact, 'contact_invite', [
            'contact_permissions' => $allowed === [] ? 'sign in to the portal' : self::sentence($allowed),
            'reset_url' => route('portal.password.reset', ['token' => $token, 'email' => $contact->email]),
            'expire_hours' => intdiv((int) config('auth.passwords.contacts.expire'), 60),
        ]);
    }

    /** @param  list<string>  $items */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' and '.$last;
    }
}
