<?php

namespace App\Console\Commands;

use App\Import\Whmcs\ImportException;
use App\Import\Whmcs\ImportReport;
use App\Import\Whmcs\WhmcsImporter;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhmcsImport extends Command
{
    protected $signature = 'whmcs:import
        {--dry-run : Show what would be imported, then undo it all}
        {--emails : Only bring over the emails WHMCS sent to clients, into an install already imported from WHMCS. Safe to run again}
        {--connection=whmcs : Database connection for the WHMCS database (see WHMCS_DB_* in .env)}
        {--attachments= : Path to the WHMCS attachments folder, to bring ticket attachments over}
        {--downloads= : Path to the WHMCS downloads folder, to bring files on its Downloads page over}
        {--hash= : The cc_encryption_hash from WHMCS\'s configuration.php, to bring server and service passwords over}
        {--report= : Where to write the full report (default: storage/app/whmcs/)}';

    protected $description = 'Import servers, clients, products, services, invoices, payments, tickets and support content from a WHMCS database';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $emails = (bool) $this->option('emails');
        $attachments = $this->option('attachments');
        $downloads = $this->option('downloads');

        try {
            $source = $this->source($attachments, $downloads);

            $question = $emails ? 'Bring the emails WHMCS sent to clients over now?' : 'Import WHMCS into this install now? Run with --dry-run first to check the report.';

            if (! $dryRun && ! $this->confirm($question, true)) {
                return self::FAILURE;
            }

            $this->info($dryRun ? 'Dry run: reading WHMCS and undoing everything at the end…' : 'Importing from WHMCS…');
            $importer = new WhmcsImporter($attachments, $this->option('hash'), $downloads);
            $report = $emails ? $importer->runEmails($source, $dryRun) : $importer->run($source, $dryRun);
        } catch (ImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->show($report);
        $path = $report->save($this->option('report'));
        $this->newLine();
        $this->line("Full report, with every skipped record: {$path}");

        if (! $emails) {
            $this->line('Custom WHMCS reports (PHP files in modules/reports) are not copied, since only the database is read. Admin > Reports has the closest built-in ones.');
        }

        if ($dryRun) {
            $this->info('Nothing was saved. Run again without --dry-run to import.');
        } elseif ($emails) {
            $this->info('Emails brought over. Each client\'s page lists the ones sent to them.');
        } else {
            $this->info('Imported. Clients log in with their WHMCS password; staff need new accounts (php artisan admin:create).');
        }

        return self::SUCCESS;
    }

    /** @throws ImportException when the attachments or downloads folder or the WHMCS database can't be reached */
    private function source(?string $attachments, ?string $downloads): ConnectionInterface
    {
        foreach ([$attachments, $downloads] as $folder) {
            if ($folder !== null && ! is_dir($folder)) {
                throw new ImportException("No folder at {$folder}.");
            }
        }

        try {
            $source = DB::connection($this->option('connection'));
            $source->getPdo();
        } catch (Throwable $e) {
            throw new ImportException('Could not connect to the WHMCS database: '.$e->getMessage(), previous: $e);
        }

        return $source;
    }

    private function show(ImportReport $report): void
    {
        $this->table(
            ['Records', 'In WHMCS', 'Imported', 'Skipped'],
            collect($report->counts())->map(fn (array $count, string $section) => [$section, $count['found'], $count['imported'], $count['skipped']])->values()->all(),
        );

        if ($report->checks() === []) {
            return;
        }

        $this->table(
            ['Total', 'WHMCS', 'Here', 'Match'],
            array_map(fn (array $check) => [$check['label'], $check['whmcs'], $check['here'], $check['whmcs'] === $check['here'] ? 'yes' : 'NO'], $report->checks()),
        );

        $mismatches = count($report->mismatches());
        $mismatches === 0
            ? $this->info('All totals match.')
            : $this->warn("{$mismatches} total(s) differ. The report lists the skipped records behind them.");
    }
}
