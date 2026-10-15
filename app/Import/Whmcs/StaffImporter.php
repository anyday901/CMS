<?php

namespace App\Import\Whmcs;

use App\Enums\StaffRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WHMCS admins, as staff accounts. An admin whose email already has a staff
 * account here (such as the person running the import) is matched to it
 * rather than added. Disabled admins are left out, since they couldn't log
 * in to WHMCS either; tickets they were assigned or answered keep their name.
 *
 * Each admin's role comes from the permissions their WHMCS role ticks:
 * what those add up to here is a built-in role when one matches, or the
 * custom role with just those abilities. Without role permissions on
 * record, the role's name picks the closest built-in role.
 *
 * WHMCS keeps admin passwords as bcrypt hashes, which work here as they are,
 * so admins log in with the password they had. Older or unusual hashes can't
 * be reused; those admins get a random password until one is set for them.
 */
class StaffImporter
{
    private const STAFF = 'Staff';

    /**
     * The WHMCS admin permissions, by their id in tbladminperms, that each
     * ability here needs: a role gets the ability when it has all of them.
     * The ids are the keys of WHMCS's getAdminPermsArray(), checked against
     * WHMCS 8.
     */
    private const PERMISSIONS = [
        'manage-staff' => [64 => 'Configure Administrators', 65 => 'Configure Admin Roles'],
        'manage-settings' => [63 => 'Configure General Settings'],
        'manage-clients' => [9 => 'Edit Clients Details', 12 => 'Edit Clients Products/Services'],
        'manage-billing' => [29 => 'Add Transaction', 35 => 'Manage Invoice'],
        'manage-tickets' => [105 => 'View Support Ticket'],
    ];

    /** WHMCS's Full Administrator role, which every new install has. */
    private const FULL_ADMINISTRATOR = 1;

    /** @var array<int, int> WHMCS admin id => staff user id */
    private array $users = [];

    /** @var array<string, int|null> lower-case full name => staff user id, null when two admins share it */
    private array $names = [];

    public function __construct(private ImportRun $run) {}

    public function import(): void
    {
        if (! $this->run->hasTables('tbladmins')) {
            return;
        }

        $roles = $this->run->hasTables('tbladminroles')
            ? $this->run->table('tbladminroles')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(int) $id => Whmcs::text($name)])->all()
            : [];
        $permissions = $this->run->hasTables('tbladminperms')
            ? $this->run->table('tbladminperms')->get()->groupBy(fn ($row) => (int) $row->roleid)->map(fn ($rows) => $rows->map(fn ($row) => (int) $row->permid)->unique()->all())->all()
            : [];
        $existing = DB::table('users')->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [strtolower($email) => (int) $id])->all();
        $newPasswords = 0;

        foreach ($this->run->table('tbladmins')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::STAFF);
            $email = strtolower(trim((string) ($row->email ?? '')));
            $name = trim(Whmcs::text($row->firstname ?? '').' '.Whmcs::text($row->lastname ?? '')) ?: (Whmcs::short($row->username ?? '') ?? $email);
            $label = $name !== '' ? $name : "Admin {$row->id}";

            $problem = match (true) {
                Whmcs::on($row->disabled ?? '') => "{$label} is disabled in WHMCS, so has no account here.",
                $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) => "{$label} has no valid email address.",
                default => null,
            };

            if ($problem !== null) {
                $this->run->report->skipped(self::STAFF, $problem);

                continue;
            }

            if (isset($existing[$email])) {
                $this->remember((int) $row->id, $existing[$email], $name);
                $this->run->report->note(self::STAFF, "{$label} ({$email}) already has a staff account here, so it was kept as it is.");

                continue;
            }

            $roleId = (int) ($row->roleid ?? 0);
            [$role, $abilities] = self::role($roleId, $roles[$roleId] ?? '', $permissions);
            $password = self::passwordHash((string) ($row->password ?? ''));
            $newPasswords += $password === null ? 1 : 0;

            $id = DB::table('users')->insertGetId([
                'name' => Str::limit($name, 250, ''),
                'email' => Str::limit($email, 250, ''),
                'password' => $password ?? bcrypt(Str::random(40)),
                'role' => $role->value,
                'permissions' => $role === StaffRole::Custom ? json_encode($abilities) : null,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $existing[$email] = $id;
            $this->remember((int) $row->id, $id, $name);

            $roleName = $roles[$roleId] ?? 'no role';
            $this->run->report->note(self::STAFF, "{$label} ({$roleName} in WHMCS) ".self::describe($role, $abilities));
            $this->run->report->imported(self::STAFF);
        }

        if ($newPasswords > 0) {
            $this->run->report->note(self::STAFF, "{$newPasswords} admin(s) had a password WHMCS kept in an older form that can't be reused. Set a new password for them under Staff before they log in.");
        }
    }

    /** The staff account of a WHMCS admin id, or null. */
    public function userId(mixed $adminId): ?int
    {
        return $this->users[(int) $adminId] ?? null;
    }

    /** The staff account of the admin WHMCS names on a ticket reply ("Jane Smith"), when only one admin has that name. */
    public function userIdByName(?string $name): ?int
    {
        $key = Str::lower(trim((string) $name));

        return $key === '' ? null : ($this->names[$key] ?? null);
    }

    private function remember(int $adminId, int $userId, string $name): void
    {
        $this->users[$adminId] = $userId;
        $key = Str::lower($name);

        if ($key !== '') {
            // Two admins with one name: replies by that name can't be told apart.
            $this->names[$key] = array_key_exists($key, $this->names) && $this->names[$key] !== $userId ? null : $userId;
        }
    }

    /**
     * The role here for a WHMCS admin role, and for the custom role the
     * abilities it ticks. Full Administrators, and roles with every permission
     * they have, get everything. Other roles get what their WHMCS permissions
     * add up to, as the built-in role with exactly those abilities when there
     * is one. When WHMCS has no role permissions on record at all, the role's
     * name picks the closest built-in role, and one this can't place gets
     * Support, the narrowest.
     *
     * @param  array<int, list<int>>  $permissions  WHMCS role id => its permission ids
     * @return array{0: StaffRole, 1: list<string>}
     */
    private static function role(int $roleId, string $name, array $permissions): array
    {
        $name = strtolower($name);
        $ticked = $permissions[$roleId] ?? [];
        $everything = $permissions[self::FULL_ADMINISTRATOR] ?? [];

        if ($roleId === self::FULL_ADMINISTRATOR || str_contains($name, 'full admin') || ($everything !== [] && array_diff($everything, $ticked) === [])) {
            return [StaffRole::Admin, StaffRole::ABILITIES];
        }

        // Without permissions on record (an old WHMCS, or none read), go by the role's name.
        if ($permissions === []) {
            return [match (true) {
                str_contains($name, 'account') || str_contains($name, 'billing') || str_contains($name, 'sales') => StaffRole::Billing,
                default => StaffRole::Support,
            }, []];
        }

        $abilities = array_values(array_filter(StaffRole::ABILITIES, fn (string $ability) => array_diff(array_keys(self::PERMISSIONS[$ability]), $ticked) === []));

        foreach ([StaffRole::Admin, StaffRole::Billing, StaffRole::Support] as $builtIn) {
            if ($abilities === array_values(array_filter(StaffRole::ABILITIES, fn (string $ability) => $builtIn->allows($ability)))) {
                return [$builtIn, []];
            }
        }

        return [StaffRole::Custom, $abilities];
    }

    /**
     * What an imported admin can do here, for the report.
     *
     * @param  list<string>  $abilities
     */
    private static function describe(StaffRole $role, array $abilities): string
    {
        if ($role !== StaffRole::Custom) {
            return "has the {$role->label()} role here.";
        }

        if ($abilities === []) {
            return 'has a custom role here that can only look, since their WHMCS role has none of the permissions the abilities here need.';
        }

        $granted = collect($abilities)->map(fn (string $ability) => lcfirst(StaffRole::abilityLabel($ability)).' (from '.implode(' and ', self::PERMISSIONS[$ability]).')');

        return 'has a custom role here that can '.$granted->join('; ', '; and ').'.';
    }

    /** A WHMCS password hash this app can check as it is (bcrypt), or null. */
    private static function passwordHash(string $hash): ?string
    {
        $info = password_get_info($hash);

        return ($info['algoName'] ?? '') === 'bcrypt' ? $hash : null;
    }
}
