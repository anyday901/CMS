<?php

namespace App\Import\Whmcs;

use App\Models\ServerGroup;
use App\Provisioning\Whmcs\ConfigFields;
use App\Provisioning\Whmcs\WhmcsModules;
use Illuminate\Support\Facades\DB;

/**
 * Servers and server groups, the module settings of products on cPanel,
 * Plesk, DirectAdmin, Proxmox and WHMCS modules uploaded here, and each
 * service's server and login.
 * Passwords are encrypted in WHMCS; with its encryption hash they come over,
 * without it they are left for staff to enter.
 */
class ServerImporter
{
    private const SERVERS = 'Servers';

    private const GROUPS = 'Server groups';

    private const LOGINS = 'Service logins';

    /** The setting each module keeps in WHMCS's first product module setting (configoption1). */
    private const PACKAGE_SETTING = ['cpanel' => 'package', 'directadmin' => 'package', 'plesk' => 'plan'];

    /** @var array<int, array{id: int, module: string}> WHMCS server id => local server */
    private array $servers = [];

    /** @var array<int, int> WHMCS server group id => local group id */
    private array $groups = [];

    private bool $badHash = false;

    public function __construct(private ImportRun $run, private ?WhmcsCrypt $crypt = null) {}

    /**
     * This app's module for a WHMCS server module name, or null when it has
     * none. A WHMCS module uploaded and enabled here is used before a built-in one.
     */
    public static function module(mixed $type): ?string
    {
        $type = strtolower(trim((string) $type));

        if ($uploaded = app(WhmcsModules::class)->keyFor($type)) {
            return $uploaded;
        }

        return match (true) {
            in_array($type, ['cpanel', 'plesk', 'directadmin'], true) => $type,
            str_contains($type, 'proxmox') || str_starts_with($type, 'pve') => 'proxmox',
            default => null,
        };
    }

    /** Whether a product's module (this app's key) is one the importer puts services on servers for. */
    private static function serverModule(?string $module): bool
    {
        return $module !== null && (str_starts_with($module, WhmcsModules::PREFIX) || in_array($module, ['cpanel', 'plesk', 'directadmin', 'proxmox'], true));
    }

    public function import(): void
    {
        if (! $this->run->hasTables('tblservers')) {
            return;
        }

        if ($this->crypt === null) {
            $this->run->report->note(self::SERVERS, 'No WHMCS encryption hash was given, so server and service passwords weren\'t brought over. Enter them on each server and service, or import again with the hash.');
        }

        $this->importServers();
        $this->importGroups();
    }

    /** The local id of a WHMCS server, or null when it wasn't imported. */
    public function serverId(mixed $whmcsId): ?int
    {
        return $this->servers[(int) $whmcsId]['id'] ?? null;
    }

    /**
     * A product's module, settings and server group, for a product on a
     * WHMCS module this app has, or null.
     *
     * @return array{module: string, module_config: ?string, server_group_id: ?int}|null
     */
    public function product(object $row): ?array
    {
        $module = self::module($row->servertype ?? null);

        if ($module === null) {
            return null;
        }

        if (str_starts_with($module, WhmcsModules::PREFIX)) {
            return $this->uploadedModuleProduct($row, $module);
        }

        $setting = self::PACKAGE_SETTING[$module] ?? null;
        $package = Whmcs::short($row->configoption1 ?? '');
        $config = $setting !== null && $package !== null ? [$setting => $package] : [];

        $this->run->report->note('Products', match (true) {
            $config !== [] => '"'.Whmcs::text($row->name)."\" uses the {$module} module with the {$setting} {$package}.",
            $module === 'proxmox' => '"'.Whmcs::text($row->name).'" uses the Proxmox module. Set its template, cores and memory on its product page; WHMCS Proxmox modules keep them their own way.',
            default => '"'.Whmcs::text($row->name)."\" uses the {$module} module. Set its {$setting} on its product page.",
        });

        return [
            'module' => $module,
            'module_config' => $config === [] ? null : json_encode($config),
            'server_group_id' => $this->groups[(int) ($row->servergroup ?? 0)] ?? null,
        ];
    }

    /**
     * A product on an uploaded WHMCS module keeps its module settings as they
     * were: configoption1 to configoption24.
     *
     * @return array{module: string, module_config: ?string, server_group_id: ?int}
     */
    private function uploadedModuleProduct(object $row, string $module): array
    {
        $config = [];

        for ($i = 1; $i <= ConfigFields::MAX; $i++) {
            $value = trim(Whmcs::text($row->{"configoption{$i}"} ?? ''));

            if ($value !== '') {
                $config["configoption{$i}"] = $value;
            }
        }

        $this->run->report->note('Products', '"'.Whmcs::text($row->name)."\" uses the uploaded WHMCS module {$row->servertype}, with its settings as they were.");

        return [
            'module' => $module,
            'module_config' => $config === [] ? null : json_encode($config),
            'server_group_id' => $this->groups[(int) ($row->servergroup ?? 0)] ?? null,
        ];
    }

    /**
     * A service's server, login and saved address, as services columns.
     * Active and suspended services on a server count as set up, so the
     * modules act on their accounts from now on.
     *
     * @return array<string, mixed>
     */
    public function login(object $row, ?string $productModule): array
    {
        $server = $this->servers[(int) ($row->server ?? 0)] ?? null;
        $username = Whmcs::short($row->username ?? '', 64);

        if (($server === null && $username === null) || ! self::serverModule($productModule)) {
            return [];
        }

        $this->run->report->found(self::LOGINS);

        if ($server !== null && $server['module'] !== $productModule) {
            $this->run->report->skipped(self::LOGINS, "Service {$row->id} ({$row->domain}) is on a {$server['module']} server, but its product isn't on that module, so it wasn't put on the server.");

            return [];
        }

        $running = in_array(strtolower((string) $row->domainstatus), ['active', 'suspended'], true);
        $ip = trim((string) ($row->dedicatedip ?? ''));
        $this->run->report->imported(self::LOGINS);

        if ($server !== null && $server['module'] === 'proxmox' && $running) {
            $this->run->report->note(self::LOGINS, "Service {$row->id} ({$row->domain}) is a Proxmox VM. WHMCS Proxmox modules keep the VM ID their own way, so suspend, terminate and upgrades won't reach it until it's made again here.");
        }

        return [
            'server_id' => $server['id'] ?? null,
            'username' => $username,
            'password' => ($password = $this->decrypt($row->password ?? null)) === null ? null : encrypt($password, false),
            'provisioned_at' => $server !== null && $running ? $this->run->now : null,
            'provisioning_data' => $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? json_encode(['ip' => $ip]) : null,
        ];
    }

    private function importServers(): void
    {
        foreach ($this->run->table('tblservers')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::SERVERS);
            $module = self::module($row->type ?? null);
            $hostname = Whmcs::short($row->hostname ?? '') ?? Whmcs::short($row->ipaddress ?? '');

            if ($module === null || $hostname === null) {
                $this->run->report->skipped(self::SERVERS, 'Server "'.Whmcs::text($row->name ?? '')."\" ({$row->type}) ".($module === null ? 'uses a WHMCS module this app doesn\'t have.' : 'has no hostname or IP address.'));

                continue;
            }

            // A server staff already added here is used as it is.
            $id = DB::table('servers')->where('module', $module)->where('hostname', $hostname)->value('id')
                ?? DB::table('servers')->insertGetId($this->serverRow($row, $module, $hostname));

            $this->servers[(int) $row->id] = ['id' => (int) $id, 'module' => $module];
            $this->run->report->imported(self::SERVERS);
        }
    }

    /** @return array<string, mixed> */
    private function serverRow(object $row, string $module, string $hostname): array
    {
        $ip = trim((string) ($row->ipaddress ?? ''));
        $password = $this->decrypt($row->password ?? null);
        $token = preg_replace('/\s+/', '', (string) ($row->accesshash ?? ''));

        return [
            'name' => Whmcs::short($row->name ?? '') ?? $hostname,
            'module' => $module,
            'hostname' => $hostname,
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
            'assigned_ips' => trim(Whmcs::text($row->assignedips ?? '')) ?: null,
            'port' => (int) ($row->port ?? 0) ?: null,
            'secure' => Whmcs::on($row->secure ?? ''),
            'verify_tls' => true,
            'username' => Whmcs::short($row->username ?? ''),
            'password' => $password === null ? null : encrypt($password, false),
            'api_token' => $token === '' ? null : encrypt($token, false),
            'max_accounts' => (int) ($row->maxaccounts ?? 0) > 0 ? (int) $row->maxaccounts : 200,
            'active' => ! Whmcs::on($row->disabled ?? ''),
            'created_at' => $this->run->now,
            'updated_at' => $this->run->now,
        ];
    }

    private function importGroups(): void
    {
        if (! $this->run->hasTables('tblservergroups')) {
            return;
        }

        $members = $this->run->hasTables('tblservergroupsrel')
            ? $this->run->table('tblservergroupsrel')->get()->groupBy('groupid')
            : collect();

        foreach ($this->run->table('tblservergroups')->orderBy('id')->cursor() as $row) {
            $this->run->report->found(self::GROUPS);
            $id = DB::table('server_groups')->insertGetId([
                'name' => Whmcs::short($row->name ?? '') ?? "Group {$row->id}",
                // WHMCS: 1 adds to the least full server, 2 fills the active server until it is full.
                'fill_type' => (int) ($row->filltype ?? 1) === 2 ? ServerGroup::FILL : ServerGroup::LEAST_USED,
                'created_at' => $this->run->now,
                'updated_at' => $this->run->now,
            ]);

            $servers = $members->get($row->id, collect())
                ->map(fn (object $member) => $this->servers[(int) $member->serverid]['id'] ?? null)
                ->filter()->unique();

            DB::table('server_group_server')->insert($servers->map(fn (int $server) => ['server_group_id' => $id, 'server_id' => $server])->values()->all());
            $this->groups[(int) $row->id] = $id;
            $this->run->report->imported(self::GROUPS);
        }
    }

    /** A WHMCS password in plain text, or null without the hash or when it doesn't decrypt. */
    private function decrypt(mixed $value): ?string
    {
        if ($this->crypt === null || blank($value)) {
            return null;
        }

        $plain = $this->crypt->decrypt((string) $value);

        if ($plain === null && ! $this->badHash) {
            $this->badHash = true;
            $this->run->report->note(self::SERVERS, 'Some passwords didn\'t decrypt, so they weren\'t brought over. Check the encryption hash is the cc_encryption_hash from WHMCS\'s configuration.php.');
        }

        return $plain;
    }
}
