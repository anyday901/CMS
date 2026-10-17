<?php

namespace App\Import\Whmcs;

use App\Models\EmailLog;
use App\Models\Setting;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The admin walkthrough for the WHMCS importer: remembers, for this staff
 * session, which WHMCS database to read and what the dry run found, and runs
 * the same importer as php artisan whmcs:import.
 */
class ImportWizard
{
    public const PREPARE = 1;

    public const CONNECT = 2;

    public const DRY_RUN = 3;

    public const IMPORT = 4;

    public const FINISH = 5;

    public const STEPS = [
        self::PREPARE => 'Before you start',
        self::CONNECT => 'Connect to WHMCS',
        self::DRY_RUN => 'Dry run',
        self::IMPORT => 'Import',
        self::FINISH => 'Next steps',
    ];

    /** The connection from WHMCS_DB_* in .env, as used by php artisan whmcs:import. */
    public const ENV_CONNECTION = 'whmcs';

    /** The connection built from details typed into the walkthrough. */
    private const FORM_CONNECTION = 'whmcs_wizard';

    private const SESSION = 'whmcs_import_wizard';

    private const CONNECTION_KEY = self::SESSION.'.connection';

    private const PASSWORD = 'password';

    /** WHMCS's cc_encryption_hash, kept encrypted like the password. */
    private const HASH = 'encryption_hash';

    /** Session key of the dry run summary, also the run name in report links. */
    public const DRY_RUN_SUMMARY = 'dry_run';

    /** Session key of the summary of bringing emails over after the import. */
    public const EMAILS_SUMMARY = 'emails';

    /** WHMCS tables counted when connecting, to show the right database was picked. */
    private const PREVIEW = ['Clients' => 'tblclients', 'Services' => 'tblhosting', 'Invoices' => 'tblinvoices', 'Tickets' => 'tbltickets', 'Knowledge base articles' => 'tblknowledgebase'];

    /** Notes kept per report section for the page; the saved report has them all. */
    private const NOTES_SHOWN = 10;

    public function __construct(private Session $session) {}

    /** The furthest step that can be opened now. */
    public function reachable(): int
    {
        return match (true) {
            $this->imported() !== null => self::FINISH,
            $this->summary(self::DRY_RUN_SUMMARY) !== null => self::IMPORT,
            $this->connection() !== null => self::DRY_RUN,
            WhmcsImporter::blockers() === [] => self::CONNECT,
            default => self::PREPARE,
        };
    }

    /** @return array{imported_at: string, last_invoice_id: int}|null when this install was imported from WHMCS */
    public function imported(): ?array
    {
        $value = Setting::find(WhmcsImporter::SETTING)?->value;

        return $value === null ? null : json_decode((string) $value, true);
    }

    /** How many emails sent by WHMCS are here. */
    public function emailsHere(): int
    {
        return EmailLog::whereNotNull('whmcs_id')->count();
    }

    /** @return array<string, mixed>|null the WHMCS database picked in step 2, without its password */
    public function connection(): ?array
    {
        $connection = $this->session->get(self::CONNECTION_KEY);

        return $connection === null ? null : array_diff_key($connection, [self::PASSWORD => true, self::HASH => true]) + ['has_hash' => filled($connection[self::HASH] ?? null)];
    }

    /** @return array<string, mixed> the .env connection, for showing which database it points at */
    public function envConnection(): array
    {
        return array_intersect_key((array) config('database.connections.'.self::ENV_CONNECTION), array_flip(['driver', 'host', 'port', 'database', 'username']));
    }

    /**
     * Checks the WHMCS database can be read and remembers it for the next steps.
     *
     * @param  array<string, mixed>  $details  validated form input
     *
     * @throws ImportException when it can't be reached or doesn't look like WHMCS
     */
    public function connect(array $details): void
    {
        foreach ([self::folder($details, 'attachments'), self::folder($details, 'downloads')] as $folder) {
            if ($folder !== null && ! is_dir($folder)) {
                throw new ImportException("No folder at {$folder} on this server.");
            }
        }

        $details[self::PASSWORD] = Crypt::encryptString((string) ($details[self::PASSWORD] ?? ''));
        $details[self::HASH] = filled($details[self::HASH] ?? null) ? Crypt::encryptString(trim((string) $details[self::HASH])) : null;
        $source = $this->open($details);

        if (! $source->getSchemaBuilder()->hasTable('tblclients')) {
            throw new ImportException('Connected, but that database has no WHMCS tables (tblclients is missing). Check the database name.');
        }

        $details['preview'] = $this->preview($source);
        $this->session->forget(self::SESSION);
        $this->session->put(self::CONNECTION_KEY, $details);
    }

    /**
     * Runs the importer against the WHMCS database from step 2 and keeps a summary for the page.
     *
     * @throws ImportException when the install isn't empty or WHMCS can't be reached
     */
    public function run(bool $dryRun): ImportReport
    {
        $details = $this->session->get(self::CONNECTION_KEY) ?? throw new ImportException('Connect to the WHMCS database first.');

        if (! $dryRun && $this->summary(self::DRY_RUN_SUMMARY) === null) {
            throw new ImportException('Run the dry run before importing.');
        }

        // A big WHMCS install can take minutes; let it finish even if the browser gives up waiting.
        set_time_limit(0);
        ignore_user_abort(true);

        $hash = filled($details[self::HASH] ?? null) ? Crypt::decryptString($details[self::HASH]) : null;
        $report = (new WhmcsImporter(self::folder($details, 'attachments'), $hash, self::folder($details, 'downloads')))
            ->run($this->open($details), $dryRun);
        $this->session->put(self::SESSION.'.'.($dryRun ? self::DRY_RUN_SUMMARY : 'import'), $this->summarize($report));

        return $report;
    }

    /**
     * Brings the emails WHMCS sent to clients over into this already imported
     * install, from the WHMCS database connected in this session.
     *
     * @throws ImportException when no WHMCS database is connected or this install wasn't imported from WHMCS
     */
    public function runEmails(): ImportReport
    {
        $details = $this->session->get(self::CONNECTION_KEY) ?? throw new ImportException('Connect to the WHMCS database first.');

        set_time_limit(0);
        ignore_user_abort(true);

        $report = (new WhmcsImporter)->runEmails($this->open($details), dryRun: false);
        $this->session->put(self::SESSION.'.'.self::EMAILS_SUMMARY, $this->summarize($report));

        return $report;
    }

    /** @return array{counts: array<string, array{found: int, imported: int, skipped: int}>, checks: list<array{label: string, whmcs: string, here: string}>, mismatches: int, notes: array<string, array{total: int, shown: list<string>}>, path: string}|null */
    public function summary(string $run): ?array
    {
        return $this->session->get(self::SESSION.'.'.$run);
    }

    /** Forgets the connection and dry run, to start the walkthrough again. */
    public function reset(): void
    {
        $this->session->forget(self::SESSION);
    }

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws ImportException
     */
    private function open(array $details): ConnectionInterface
    {
        $name = self::ENV_CONNECTION;

        if ($details['source'] === 'form') {
            $name = self::FORM_CONNECTION;
            config(['database.connections.'.$name => [
                ...array_intersect_key($details, array_flip(['driver', 'host', 'port', 'database', 'username'])),
                self::PASSWORD => Crypt::decryptString($details[self::PASSWORD]),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ]]);
            DB::purge($name);
        }

        try {
            $source = DB::connection($name);
            $source->getPdo();
        } catch (Throwable $e) {
            throw new ImportException('Could not connect to the WHMCS database: '.$e->getMessage(), previous: $e);
        }

        return $source;
    }

    /**
     * The WHMCS attachments or downloads folder given, or null.
     *
     * @param  array<string, mixed>  $details
     */
    private static function folder(array $details, string $key): ?string
    {
        return filled($details[$key] ?? null) ? (string) $details[$key] : null;
    }

    /** @return array<string, int|null> */
    private function preview(ConnectionInterface $source): array
    {
        return array_map(
            fn (string $table) => $source->getSchemaBuilder()->hasTable($table) ? $source->table($table)->count() : null,
            self::PREVIEW,
        );
    }

    /** @return array<string, mixed> */
    private function summarize(ImportReport $report): array
    {
        return [
            'counts' => $report->counts(),
            'checks' => $report->checks(),
            'mismatches' => count($report->mismatches()),
            'notes' => array_map(fn (array $notes) => ['total' => count($notes), 'shown' => array_slice($notes, 0, self::NOTES_SHOWN)], $report->notes()),
            'path' => $report->save(),
        ];
    }
}
