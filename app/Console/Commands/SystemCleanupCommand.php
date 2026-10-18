<?php

namespace App\Console\Commands;

use App\System\SystemCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** The command line side of Settings > Cleanup. */
class SystemCleanupCommand extends Command
{
    protected $signature = 'system:cleanup
        {--before= : Delete what was made before this date (YYYY-MM-DD)}
        {--days= : Or: delete what is older than this many days}
        {--only=* : Only these targets (attachments, emails, activity, gateway_log, login_attempts, inbound_emails, webhook_deliveries, sms_log, task_runs, failed_jobs)}
        {--dry-run : Only count what would be deleted}
        {--force : Delete without asking}';

    protected $description = 'Delete old ticket attachments and log entries (never invoices, payments or other financial records)';

    public function handle(SystemCleanup $cleanup): int
    {
        $before = $this->before();
        if ($before === null) {
            $this->error('Give --before=YYYY-MM-DD (today or earlier) or --days=N.');

            return self::FAILURE;
        }

        $targets = $this->option('only') ?: null;

        try {
            $counts = $cleanup->preview($before, $targets);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['What', 'Before '.$before->toDateString()], collect($counts)->map(fn (int $count, string $key) => [SystemCleanup::TARGETS[$key], $count])->values()->all());

        if ($this->option('dry-run') || array_sum($counts) === 0) {
            $this->info('Nothing deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete these for good?')) {
            $this->info('Nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = $cleanup->run($before, $targets);
        $this->info('Deleted '.array_sum($deleted).' item(s).');

        return self::SUCCESS;
    }

    private function before(): ?CarbonImmutable
    {
        $date = (string) $this->option('before');
        $days = (string) $this->option('days');

        $before = match (true) {
            $date !== '' => self::date($date),
            $days !== '' && ctype_digit($days) && (int) $days > 0 => CarbonImmutable::now()->subDays((int) $days),
            default => null,
        };

        return $before !== null && $before->lte(CarbonImmutable::today()) ? $before : null;
    }

    /** A real YYYY-MM-DD date, or null: PHP would read 2026-02-31 as March 3. */
    private static function date(string $date): ?CarbonImmutable
    {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date) : null;

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
