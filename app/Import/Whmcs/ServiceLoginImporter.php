<?php

namespace App\Import\Whmcs;

use App\Models\Setting;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Each service's WHMCS username and password, brought into an install
 * already imported from WHMCS. Imports made before every service kept its
 * login (or made without the encryption hash) left them out. Services keep
 * their WHMCS ids; only a username or password still empty here is filled,
 * so one staff changed since stays, and it can run any number of times.
 */
class ServiceLoginImporter
{
    public const SECTION = 'Service logins';

    /**
     * @param  array<int, string>  $emails  each client's email here, by id, to check they are the same person in WHMCS
     */
    public function __construct(private ImportRun $run, private ServerImporter $servers, private array $emails) {}

    /**
     * @param  string|null  $encryptionHash  WHMCS's cc_encryption_hash; without it only usernames come over
     *
     * @throws ImportException when this install wasn't imported from WHMCS
     */
    public static function bringOver(ConnectionInterface $source, ?string $encryptionHash, bool $dryRun): ImportReport
    {
        if (! Setting::whereKey(WhmcsImporter::SETTING)->exists()) {
            throw new ImportException('This install wasn\'t imported from WHMCS, so there are no WHMCS services to add logins to. Run the full import instead.');
        }

        $run = new ImportRun($source, new ImportReport($dryRun));
        $crypt = filled($encryptionHash) ? new WhmcsCrypt(trim($encryptionHash)) : null;
        $lastClient = json_decode((string) Setting::find(WhmcsImporter::SETTING)?->value, true)['last_client_id'] ?? null;
        // Anonymised clients had their details wiped on purpose, so they get none back.
        $emails = DB::table('clients')
            ->whereNull('anonymised_at')
            ->when($lastClient !== null, fn ($query) => $query->where('id', '<=', $lastClient))
            ->pluck('email', 'id')
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->all();

        if ($crypt === null) {
            $run->report->note(self::SECTION, 'No WHMCS encryption hash was given, so only usernames came over. Run again with the hash to bring the passwords too.');
        }

        DB::beginTransaction();

        try {
            (new self($run, new ServerImporter($run, $crypt), $emails))->import();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $run->report;
    }

    public function import(): void
    {
        if (! $this->run->hasTables('tblhosting')) {
            return;
        }

        $already = 0;
        $this->run->chunk('tblhosting', function (Collection $rows) use (&$already) {
            $here = DB::table('services')->join('products', 'products.id', '=', 'services.product_id')
                ->whereIn('services.id', $rows->pluck('id'))
                ->get(['services.id', 'services.client_id', 'services.username', 'services.password', 'products.module'])->keyBy('id');
            $addresses = $this->run->table('tblclients')->whereIn('id', $rows->pluck('userid')->unique())->pluck('email', 'id');

            foreach ($rows as $row) {
                $already += $this->bring($row, $here->get((int) $row->id), Str::lower(trim((string) ($addresses[$row->userid] ?? '')))) ? 0 : 1;
            }
        });

        if ($already === 1) {
            $this->run->report->note(self::SECTION, '1 service already had its login here and was left as it is.');
        } elseif ($already > 1) {
            $this->run->report->note(self::SECTION, number_format($already).' services already had their login here and were left as they are.');
        }
    }

    /**
     * Fills in one WHMCS service's login here where it is still empty.
     *
     * @return bool false when the service already had its whole login here
     */
    private function bring(object $row, ?object $service, string $whmcsEmail): bool
    {
        $login = $this->servers->credentials($row, ServerImporter::serverModule($service?->module));

        if ($login === []) {
            return true;
        }

        $this->run->report->found(self::SECTION);
        $missing = $this->missing($row, $service, $whmcsEmail);

        if ($missing !== null) {
            $this->run->report->skipped(self::SECTION, "Service {$row->id} ({$row->domain}) {$missing}.");

            return true;
        }

        $fill = array_filter([
            'username' => blank($service->username) ? $login['username'] : null,
            'password' => blank($service->password) ? $login['password'] : null,
        ], fn ($value) => $value !== null);

        if ($fill === []) {
            return false;
        }

        DB::table('services')->where('id', $service->id)->update($fill + ['updated_at' => $this->run->now]);
        $this->run->report->imported(self::SECTION);

        return true;
    }

    /** Why a WHMCS service's login doesn't go on the service here, or null when it does. */
    private function missing(object $row, ?object $service, string $whmcsEmail): ?string
    {
        $client = (int) $row->userid;

        return match (true) {
            $service === null => "isn't here",
            (int) $service->client_id !== $client => "belongs to client {$client} in WHMCS but another client here, so it may be a different service",
            ! isset($this->emails[$client]) => "belongs to client {$client}, who isn't here",
            $this->emails[$client] !== $whmcsEmail => "belongs to client {$client}, whose email in WHMCS ({$whmcsEmail}) isn't the one here ({$this->emails[$client]}), so it may be someone else",
            default => null,
        };
    }
}
