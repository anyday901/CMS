<?php

namespace App\Import\Whmcs;

use Illuminate\Support\Facades\DB;

/**
 * Who signs in to the portal after a WHMCS import: sub-account contacts and
 * WHMCS 8 users keep a login, with the permissions that match ones here, as
 * long as no client signs in with their email. A WHMCS 8 user of several
 * clients becomes a contact on each, sharing one login.
 */
class ContactLoginImporter
{
    /** WHMCS sub-account permissions and the contact permission each one gives here. */
    private const PERMISSIONS = [
        'invoices' => 'billing',
        'orders' => 'orders',
        'products' => 'services',
        'manageproducts' => 'services',
        'productsso' => 'services',
        'tickets' => 'support',
    ];

    /** @var array<string, true> clients' emails, which no contact can sign in with */
    private array $clientEmails = [];

    public function __construct(private ImportRun $run, private string $label) {}

    /** Marks an email as a client's own login. */
    public function claim(string $email): void
    {
        $this->clientEmails[$email] = true;
    }

    /**
     * WHMCS 8 gives other people a login to a client as users of it, rather
     * than as sub-account contacts. Each becomes a contact with portal
     * access here, or gives an existing contact with their email access.
     *
     * @param  array<int, string>  $clients  the imported clients, by id
     */
    public function importUsers(array $clients): void
    {
        if (! $this->run->hasTables('tblusers', 'tblusers_clients')) {
            return;
        }

        $this->run->flush('client_contacts');
        $users = $this->run->table('tblusers_clients')
            ->join('tblusers', 'tblusers.id', '=', 'tblusers_clients.auth_user_id')
            ->where('tblusers_clients.owner', 0)
            ->orderBy('tblusers_clients.id')
            ->select('tblusers_clients.*', 'tblusers.email', 'tblusers.first_name', 'tblusers.last_name');

        foreach ($users->cursor() as $row) {
            $email = strtolower(trim((string) $row->email));

            if (! isset($clients[(int) $row->client_id]) || $email === '') {
                continue;
            }

            $row->subaccount = 'on';
            $row->userid = $row->client_id;
            $existing = DB::table('client_contacts')->where('client_id', (int) $row->client_id)->where('email', $email)->first();

            if ($existing?->portal_access) {
                continue;
            }

            $access = $this->access($row, $email);

            if ($access === null) {
                continue;
            }

            if ($existing !== null) {
                DB::table('client_contacts')->where('id', $existing->id)->update(['portal_access' => true, 'permissions' => json_encode($access)]);

                continue;
            }

            $this->run->report->found($this->label);
            DB::table('client_contacts')->insert([
                'client_id' => (int) $row->client_id,
                'first_name' => Whmcs::short($row->first_name ?? '') ?? '-',
                'last_name' => Whmcs::short($row->last_name ?? '') ?? '',
                'email' => $email,
                'invoice_emails' => false,
                'support_emails' => false,
                'portal_access' => true,
                'permissions' => json_encode($access),
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $this->run->report->imported($this->label);
        }
    }

    /**
     * A WHMCS sub-account keeps its login, with the permissions that match
     * ones here. Its WHMCS password can't come over, so it chooses a new one
     * with Forgot password. Null for a contact who can't sign in.
     *
     * @return list<string>|null
     */
    public function access(object $row, string $email): ?array
    {
        if (! Whmcs::on($row->subaccount ?? '')) {
            return null;
        }

        if (isset($this->clientEmails[$email])) {
            $this->run->report->note($this->label, "Contact {$email} of client {$row->userid} could log in to WHMCS, but a client signs in with that email here, so this contact can't.");

            return null;
        }

        $ticked = preg_split('/\s*,\s*/', strtolower((string) ($row->permissions ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $permissions = array_values(array_unique(array_intersect_key(self::PERMISSIONS, array_flip($ticked))));
        $this->run->report->note($this->label, "Contact {$email} of client {$row->userid} can sign in to the portal (".($permissions === [] ? 'with no permissions yet' : implode(', ', $permissions))."). WHMCS passwords don't come over, so they set a new one with Forgot password.");

        return $permissions;
    }
}
