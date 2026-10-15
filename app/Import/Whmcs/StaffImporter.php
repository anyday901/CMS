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
 * WHMCS keeps admin passwords as bcrypt hashes, which work here as they are,
 * so admins log in with the password they had. Older or unusual hashes can't
 * be reused; those admins get a random password until one is set for them.
 */
class StaffImporter
{
    private const STAFF = 'Staff';

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

            $role = self::role((int) ($row->roleid ?? 0), $roles[(int) ($row->roleid ?? 0)] ?? '');
            $password = self::passwordHash((string) ($row->password ?? ''));
            $newPasswords += $password === null ? 1 : 0;

            $id = DB::table('users')->insertGetId([
                'name' => Str::limit($name, 250, ''),
                'email' => Str::limit($email, 250, ''),
                'password' => $password ?? bcrypt(Str::random(40)),
                'role' => $role->value,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);
            $existing[$email] = $id;
            $this->remember((int) $row->id, $id, $name);

            $roleName = $roles[(int) ($row->roleid ?? 0)] ?? 'no role';
            $this->run->report->note(self::STAFF, "{$label} ({$roleName} in WHMCS) has the {$role->label()} role here.");
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
     * The closest role here to a WHMCS admin role. Only Full Administrators
     * get everything; roles this can't place get Support, the narrowest.
     */
    private static function role(int $roleId, string $name): StaffRole
    {
        $name = strtolower($name);

        return match (true) {
            $roleId === 1 || str_contains($name, 'full admin') => StaffRole::Admin,
            str_contains($name, 'account') || str_contains($name, 'billing') || str_contains($name, 'sales') => StaffRole::Billing,
            default => StaffRole::Support,
        };
    }

    /** A WHMCS password hash this app can check as it is (bcrypt), or null. */
    private static function passwordHash(string $hash): ?string
    {
        $info = password_get_info($hash);

        return ($info['algoName'] ?? '') === 'bcrypt' ? $hash : null;
    }
}
