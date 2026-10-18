<?php

namespace App\Clients;

use App\Affiliates\AffiliateMerger;
use App\Billing\CreditLedger;
use App\Hooks\HookCatalog;
use App\Hooks\HookEvent;
use App\Hooks\Hooks;
use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientNote;
use App\Models\LoginAttempt;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Merges one client account into another: everything on the first account
 * (services, domains, invoices, quotes, payments, orders, tickets, contacts, notes, to-dos,
 * emails, logins, activity and affiliate records) moves to the second, its credit balance is
 * added there, and the first account is deleted. Its details are kept in a
 * note on the account it was merged into.
 */
class ClientMerger
{
    /** Tables whose rows belong to a client and move with the merge. Credit history is summed up instead. */
    public const MOVES = [
        'services', 'domains', 'invoices', 'quotes', 'transactions', 'orders', 'tickets', 'ticket_messages', 'ticket_feedback',
        'email_logs', 'activity_log', 'login_attempts', 'client_notes', 'todos', 'sms_log',
    ];

    /** Tables with a client_id that don't move row by row: contacts are matched up, credit history is summed, and AffiliateMerger sorts out affiliates and referrals. */
    public const HANDLED = ['client_contacts', 'credit_entries', 'affiliates', 'affiliate_referrals'];

    public function __construct(private CreditLedger $ledger, private AffiliateMerger $affiliates) {}

    /** Why these two can't be merged, or null when they can. */
    public static function problem(Client $from, Client $into): ?string
    {
        return match (true) {
            $from->is($into) => 'Pick a different client to merge into.',
            $from->currency !== $into->currency => "{$from->fullName()} is billed in {$from->currency} and {$into->fullName()} in {$into->currency}, so their invoices and credit can't be combined.",
            default => null,
        };
    }

    /**
     * How many of each thing will move, for the confirmation page.
     *
     * @return array<string, int>
     */
    public static function counts(Client $from): array
    {
        return [
            'services' => $from->services()->count(),
            'domains' => $from->domains()->count(),
            'invoices' => $from->invoices()->count(),
            'quotes' => $from->quotes()->count(),
            'orders' => $from->orders()->count(),
            'tickets' => $from->tickets()->count(),
            'contacts' => $from->contacts()->count(),
            'notes' => $from->clientNotes()->count(),
        ];
    }

    /**
     * @param  bool  $keepAsContact  add the merged client's account holder as a contact, when their email isn't on the account already
     *
     * @throws InvalidArgumentException when they can't be merged
     */
    public function merge(Client $from, Client $into, bool $keepAsContact = true): void
    {
        if (($problem = self::problem($from, $into)) !== null) {
            throw new InvalidArgumentException($problem);
        }

        DB::transaction(function () use ($from, $into, $keepAsContact) {
            $from = Client::lockForUpdate()->findOrFail($from->id);
            $counts = self::counts($from);

            $this->mergeContacts($from, $into);
            if ($keepAsContact) {
                $this->keepAccountHolder($from, $into);
            }
            $this->keepDetails($from, $into);

            $this->affiliates->merge($from, $into);

            foreach (self::MOVES as $table) {
                DB::table($table)->where('client_id', $from->id)->update(['client_id' => $into->id]);
            }

            // The old account's own entries in the activity log and login history point at the new one.
            DB::table('activity_log')->where('subject_type', $from->getMorphClass())->where('subject_id', $from->id)->update(['subject_id' => $into->id]);
            LoginAttempt::where('account_type', $from->getMorphClass())->where('account_id', $from->id)->update(['account_type' => null, 'account_id' => null]);

            if ($from->credit_balance !== 0) {
                $this->ledger->change($into->id, $from->credit_balance, "Credit balance of client #{$from->id} ({$from->fullName()}), merged into this account");
            }

            // Add-ons move rows in their own tables here, inside this transaction and before the old account is deleted.
            app(Hooks::class)->fire(new HookEvent(HookCatalog::CLIENT_MERGED, ['from_client_id' => $from->id, 'into_client_id' => $into->id],
                "Client #{$from->id} ({$from->fullName()}) merged into {$into->fullName()}", route('admin.clients.show', $into), $into->id));

            $from->delete();

            Activity::record("Merged client #{$from->id} ({$from->fullName()}, {$from->email}) into this client", $into, $counts + [
                'credit' => Money::format($from->credit_balance, $from->currency),
            ]);
        });
    }

    /** Contacts move over; one whose email is already on the account is folded into that contact. */
    private function mergeContacts(Client $from, Client $into): void
    {
        $existing = $into->contacts()->get()->keyBy(fn (ClientContact $contact) => strtolower($contact->email));

        foreach ($from->contacts()->get() as $contact) {
            $email = strtolower($contact->email);
            $same = $existing->get($email);

            if ($same === null && $email !== strtolower($into->email)) {
                $contact->client_id = $into->id;
                $contact->save();
                $existing->put($email, $contact);

                continue;
            }

            if ($same !== null) {
                $same->forceFill([
                    'invoice_emails' => $same->invoice_emails || $contact->invoice_emails,
                    'support_emails' => $same->support_emails || $contact->support_emails,
                    'portal_access' => $same->portal_access || $contact->portal_access,
                    'permissions' => array_values(array_unique([...($same->permissions ?? []), ...($contact->permissions ?? [])])),
                    'phone' => $same->phone ?: $contact->phone,
                ])->save();

                // Its sign-in history now belongs to the contact it was folded into.
                LoginAttempt::where('account_type', $contact->getMorphClass())->where('account_id', $contact->id)
                    ->update(['account_id' => $same->id]);
            }

            $contact->delete();
        }
    }

    /** The merged account's holder stays reachable as a contact, without a login. */
    private function keepAccountHolder(Client $from, Client $into): void
    {
        $email = strtolower((string) $from->email);

        if ($email === '' || $email === strtolower($into->email) || $into->contacts()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return;
        }

        $into->contacts()->create([
            'first_name' => $from->first_name,
            'last_name' => $from->last_name,
            'email' => $from->email,
            'phone' => $from->phone,
        ]);
    }

    /** A note with the merged account's details, so nothing about it is lost. */
    private function keepDetails(Client $from, Client $into): void
    {
        $lines = array_filter([
            "Merged from client #{$from->id} on ".now()->format('M j, Y').'.',
            "Name: {$from->fullName()}".($from->company ? ", {$from->company}" : ''),
            "Email: {$from->email}",
            $from->phone ? "Phone: {$from->phone}" : null,
            ($address = collect([$from->address1, $from->address2, $from->city, $from->state, $from->postcode, $from->country])->filter()->join(', ')) ? "Address: {$address}" : null,
            "Client since: {$from->created_at?->format('M j, Y')}",
            $from->notes ? "Profile notes: {$from->notes}" : null,
        ]);

        $note = new ClientNote(['body' => implode("\n", $lines), 'pinned' => false]);
        $note->client_id = $into->id;
        $note->user_id = Auth::guard('web')->id();
        $note->save();
    }
}
