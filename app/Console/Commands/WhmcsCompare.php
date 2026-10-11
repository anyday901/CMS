<?php

namespace App\Console\Commands;

use App\Import\Whmcs\BillingComparison;
use App\Import\Whmcs\WhmcsImporter;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class WhmcsCompare extends Command
{
    protected $signature = 'whmcs:compare
        {--from= : First renewal date to compare (default: the import date)}
        {--to= : Last renewal date to compare (default: a month after --from)}
        {--simulate : Generate this app\'s renewals for the period without saving them, instead of comparing invoices it already made}
        {--connection=whmcs : Database connection for the WHMCS database}
        {--report= : Where to write the full report (default: storage/app/whmcs/)}';

    protected $description = 'Compare the renewal invoices WHMCS and this app made for the same period after an import';

    public function handle(BillingComparison $comparison): int
    {
        try {
            $source = DB::connection($this->option('connection'));
            $source->getPdo();
            BillingComparison::lastImportedInvoice();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Could not connect to the WHMCS database: '.$e->getMessage());

            return self::FAILURE;
        }

        $imported = json_decode((string) Setting::find(WhmcsImporter::SETTING)?->value, true);
        $from = Carbon::parse($this->option('from') ?? $imported['imported_at'])->startOfDay();
        $to = $this->option('to') ? Carbon::parse($this->option('to'))->startOfDay() : $from->copy()->addMonthNoOverflow()->subDay();
        $simulate = (bool) $this->option('simulate');

        $lines = $comparison->compare($source, $from, $to, $simulate);
        $counts = array_count_values(array_column($lines, 'result'));

        $this->info("Renewals due {$from->toDateString()} to {$to->toDateString()}".($simulate ? ' (simulated here, nothing saved)' : '').':');
        $this->table(['Result', 'Lines'], array_map(
            fn (string $result) => [ucfirst($result), $counts[$result] ?? 0],
            [BillingComparison::MATCHED, BillingComparison::DIFFERENT, BillingComparison::ONLY_WHMCS, BillingComparison::ONLY_HERE],
        ));

        $path = $this->option('report') ?: storage_path('app/whmcs/compare-'.now()->format('Ymd-His').'.md');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, BillingComparison::toMarkdown($lines, $from, $to, $simulate));
        $this->line("Every line that differs: {$path}");

        $differences = count($lines) - ($counts[BillingComparison::MATCHED] ?? 0);
        $differences === 0 ? $this->info('Both systems billed the same.') : $this->warn("{$differences} line(s) differ.");

        return self::SUCCESS;
    }
}
