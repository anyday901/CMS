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
        {--connection=whmcs : Database connection for the WHMCS database (see WHMCS_DB_* in .env)}
        {--attachments= : Path to the WHMCS attachments folder, to bring ticket attachments over}
        {--report= : Where to write the full report (default: storage/app/whmcs/)}';

    protected $description = 'Import clients, products, services, invoices, payments and tickets from a WHMCS database';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $attachments = $this->option('attachments');

        try {
            $source = $this->source($attachments);

            if (! $dryRun && ! $this->confirm('Import WHMCS into this install now? Run with --dry-run first to check the report.', true)) {
                return self::FAILURE;
            }

            $this->info($dryRun ? 'Dry run: reading WHMCS and undoing everything at the end…' : 'Importing from WHMCS…');
            $report = (new WhmcsImporter($attachments))->run($source, $dryRun);
        } catch (ImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->show($report);
        $path = $this->save($report);
        $this->newLine();
        $this->line("Full report, with every skipped record: {$path}");

        if ($dryRun) {
            $this->info('Nothing was saved. Run again without --dry-run to import.');
        } else {
            $this->info('Imported. Clients log in with their WHMCS password; staff need new accounts (php artisan admin:create).');
        }

        return self::SUCCESS;
    }

    /** @throws ImportException when the attachments folder or the WHMCS database can't be reached */
    private function source(?string $attachments): ConnectionInterface
    {
        if ($attachments !== null && ! is_dir($attachments)) {
            throw new ImportException("No folder at {$attachments}.");
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

        $this->table(
            ['Total', 'WHMCS', 'Here', 'Match'],
            array_map(fn (array $check) => [$check['label'], $check['whmcs'], $check['here'], $check['whmcs'] === $check['here'] ? 'yes' : 'NO'], $report->checks()),
        );

        $mismatches = count($report->mismatches());
        $mismatches === 0
            ? $this->info('All totals match.')
            : $this->warn("{$mismatches} total(s) differ. The report lists the skipped records behind them.");
    }

    private function save(ImportReport $report): string
    {
        $prefix = $report->dryRun ? 'import-dry-run-' : 'import-';
        $path = $this->option('report') ?: storage_path('app/whmcs/'.$prefix.now()->format('Ymd-His').'.md');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $report->toMarkdown());

        return $path;
    }
}
