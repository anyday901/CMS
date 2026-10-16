<?php

namespace App\System;

use App\Http\Controllers\Admin\CronController;
use App\Models\EmailLog;
use App\Models\ScheduledTaskRun;
use App\System\Backups\BackupManager;
use App\System\Backups\DatabaseDumper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Throwable;

/**
 * The checks on the system health page and the /health endpoint: whether
 * PHP, the database, the scheduler, the queue, email, storage and backups
 * are in order.
 */
class HealthCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const PROBLEM = 'problem';

    /** PHP extensions the app needs. */
    public const EXTENSIONS = ['ctype', 'curl', 'dom', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo', 'tokenizer', 'xml', 'zip', 'zlib'];

    /** Below this much free disk space, the page warns. */
    private const LOW_DISK_BYTES = 1024 ** 3;

    public function __construct(private BackupManager $backups, private UpdateChecker $updates) {}

    /** Each check: its method and key, and its label. */
    private const CHECKS = [
        'php' => 'PHP version', 'extensions' => 'PHP extensions', 'database' => 'Database', 'migrations' => 'Database tables',
        'scheduler' => 'Cron and scheduler', 'queue' => 'Queue', 'mail' => 'Email', 'storage' => 'Folders', 'disk' => 'Disk space',
        'debug' => 'Debug mode', 'https' => 'HTTPS', 'backups' => 'Backups', 'maintenance' => 'Maintenance mode', 'updates' => 'Updates',
    ];

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function run(bool $checkUpdates = true): array
    {
        $checks = [];
        foreach (array_keys(self::CHECKS) as $key) {
            if ($key === 'updates' && ! $checkUpdates) {
                continue;
            }

            // A check that throws (say, the database went away) is a problem, not a broken health page.
            try {
                $checks[] = $this->{$key}();
            } catch (Throwable $e) {
                $checks[] = $this->check($key, self::PROBLEM, 'Couldn\'t check this: '.Str::limit($e->getMessage(), 200));
            }
        }

        return array_values(array_filter($checks));
    }

    /** The worst status of a set of checks. */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::PROBLEM, $statuses, true) => self::PROBLEM,
            in_array(self::WARNING, $statuses, true) => self::WARNING,
            default => self::OK,
        };
    }

    private function php(): array
    {
        return $this->check('php', version_compare(PHP_VERSION, '8.3.0', '>=') ? self::OK : self::PROBLEM, 'PHP '.PHP_VERSION.'; CMS needs 8.3 or newer.');
    }

    private function extensions(): array
    {
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));

        return $missing === []
            ? $this->check('extensions', self::OK, 'All '.count(self::EXTENSIONS).' extensions CMS needs are loaded.')
            : $this->check('extensions', self::PROBLEM, 'Missing: '.implode(', ', $missing).'. Install them (such as php8.3-'.$missing[0].') and restart PHP-FPM.');
    }

    private function database(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $driver = $connection->getDriverName();
            $version = $driver === 'sqlite' ? 'SQLite '.$connection->selectOne('select sqlite_version() as v')->v : $connection->getServerVersion();

            return $this->check('database', self::OK, "Connected to {$version}.".(DatabaseDumper::supports($driver) ? '' : ' Backups from the admin area need MySQL, MariaDB or SQLite.'));
        } catch (Throwable $e) {
            return $this->check('database', self::PROBLEM, 'Can\'t connect: '.$e->getMessage());
        }
    }

    private function migrations(): ?array
    {
        try {
            $migrator = app('migrator');
            $ran = $migrator->getRepository()->getRan();
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            $pending = count(array_diff($files, $ran));
        } catch (Throwable) {
            return null;
        }

        return $pending === 0
            ? $this->check('migrations', self::OK, 'Up to date.')
            : $this->check('migrations', self::PROBLEM, "{$pending} migration(s) haven't run. Run php artisan migrate --force.");
    }

    private function scheduler(): array
    {
        $last = ScheduledTaskRun::max('started_at');

        if ($last === null) {
            return $this->check('scheduler', self::PROBLEM, 'No automatic task has run yet, so invoices, reminders and suspensions aren\'t happening. Add the cron entry shown on the Cron status page.');
        }

        $last = now()->parse($last);
        $stale = $last->lt(now()->subMinutes(CronController::STALE_MINUTES));

        return $this->check('scheduler', $stale ? self::PROBLEM : self::OK, 'Last task started '.$last->diffForHumans().'.'.($stale ? ' The cron entry may have stopped.' : ''));
    }

    private function queue(): array
    {
        $queue = QueueStatus::read();

        if ($queue['driver'] === 'sync') {
            return $this->check('queue', self::WARNING, 'The sync queue sends email and runs jobs during page loads. Use database or redis with a queue worker in production.');
        }

        $stuck = $queue['oldest']?->lt(now()->subMinutes(15));
        $detail = ucfirst($queue['driver']).' queue'
            .($queue['pending'] !== null ? ", {$queue['pending']} job(s) waiting" : '')
            .($queue['failed'] ? ", {$queue['failed']} failed job(s) (php artisan queue:failed)" : '').'.'
            .($stuck ? ' The oldest has waited since '.$queue['oldest']->diffForHumans().'; is the queue worker running?' : '');

        $status = $queue['failed'] ? self::WARNING : self::OK;

        return $this->check('queue', $stuck ? self::PROBLEM : $status, $detail);
    }

    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $failed = EmailLog::where('status', EmailLog::FAILED)->where('created_at', '>=', now()->subDay())->count();

        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->check('mail', self::WARNING, 'Emails are written to the log, not sent. Set up sending under Settings > Mail.');
        }

        return $this->check('mail', $failed ? self::WARNING : self::OK, 'Sending with '.$mailer.'.'.($failed ? " {$failed} email(s) failed in the last day; see the email log." : ''));
    }

    private function storage(): array
    {
        $folders = [storage_path(), storage_path('app'), storage_path('framework/cache'), storage_path('logs'), base_path('bootstrap/cache')];
        $unwritable = array_values(array_filter($folders, fn (string $folder) => ! is_writable($folder)));

        return $unwritable === []
            ? $this->check('storage', self::OK, 'storage/ and bootstrap/cache/ are writable.')
            : $this->check('storage', self::PROBLEM, 'The web server can\'t write to '.implode(', ', $unwritable).'.');
    }

    private function disk(): ?array
    {
        $free = @disk_free_space(storage_path());

        if ($free === false) {
            return null;
        }

        return $this->check('disk', $free < self::LOW_DISK_BYTES ? self::WARNING : self::OK, Number::fileSize($free, 1).' free.');
    }

    private function debug(): array
    {
        if (! config('app.debug')) {
            return $this->check('debug', self::OK, 'Off.');
        }

        return app()->isProduction()
            ? $this->check('debug', self::PROBLEM, 'APP_DEBUG is on, so errors show passwords and settings to visitors. Set APP_DEBUG=false in .env.')
            : $this->check('debug', self::OK, 'On, outside production.');
    }

    private function https(): array
    {
        $secure = str_starts_with((string) config('app.url'), 'https://');

        return $this->check('https', $secure || ! app()->isProduction() ? self::OK : self::WARNING, $secure
            ? 'APP_URL uses https.'
            : 'APP_URL is '.config('app.url').'. Serve the site over https, and set APP_URL to match, so logins and payments are safe.');
    }

    private function backups(): array
    {
        $latest = $this->backups->latest();
        $schedule = (string) config('backups.schedule');

        if ($latest === null) {
            return $this->check('backups', self::WARNING, 'No database backup yet. Make one under Settings > Backups'.($schedule === 'off' ? ', and turn on automatic backups.' : '.'));
        }

        $maxDays = $schedule === 'weekly' ? 8 : 2;
        $old = $latest['created_at']->lt(now()->subDays($maxDays));

        return $this->check('backups', $old ? self::WARNING : self::OK, 'Last backup '.$latest['created_at']->diffForHumans().'.'.($schedule === 'off' ? ' Automatic backups are off.' : ''));
    }

    private function maintenance(): ?array
    {
        return config('maintenance.enabled')
            ? $this->check('maintenance', self::WARNING, 'On: visitors see the maintenance notice instead of the portal.')
            : null;
    }

    private function updates(): ?array
    {
        $release = $this->updates->latest();

        if ($release === null) {
            return null;
        }

        return Version::newer($release['version'])
            ? $this->check('updates', self::WARNING, "Version {$release['version']} is out; this is ".Version::current().'. See Settings > Updates.')
            : $this->check('updates', self::OK, 'Up to date ('.Version::current().').');
    }

    /** @return array{key: string, label: string, status: string, detail: string} */
    private function check(string $key, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => self::CHECKS[$key], 'status' => $status, 'detail' => $detail];
    }
}
