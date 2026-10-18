<?php

namespace App\Console;

use App\Models\InboundEmail;
use App\Models\IpBan;
use App\Models\KnownDevice;
use App\Models\LoginAttempt;
use App\Models\ScheduledTaskRun;
use App\Models\SmsLog;
use App\Models\WebhookDelivery;
use App\Support\Demo;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Every automatic task and when it runs. routes/console.php hands it the
 * scheduler; the cron status page reads it to list the tasks.
 */
class Scheduler
{
    /** The billing run's time of day when none is set under Automation. */
    public const BILLING_RUN_TIME = '00:05';

    /** After the European Central Bank publishes its rates, around 16:00 CET. */
    public const EXCHANGE_RATES_TIME = '16:30';

    public static function define(Schedule $schedule): void
    {
        $schedule->command('billing:run')->dailyAt(self::billingRunTime())->withoutOverlapping()
            ->description('Billing run: cancellations, renewal invoices, saved card charges, late fees, reminders, suspensions and terminations');
        $schedule->command('usage:collect')->hourlyAt(50)->withoutOverlapping()
            ->description('Read disk and bandwidth use from servers');
        $schedule->command('tickets:escalate')->everyFiveMinutes()->withoutOverlapping()
            ->description('Apply ticket escalation rules');
        $schedule->command('tickets:close-inactive')->everyFifteenMinutes()->withoutOverlapping()
            ->description('Close inactive tickets');
        $schedule->command('servers:check')->everyFiveMinutes()->withoutOverlapping()
            ->description('Check that monitored servers answer');
        $schedule->command('affiliates:clear')->dailyAt('02:30')->withoutOverlapping()
            ->description('Approve affiliate commissions past their clearing days');
        $schedule->command('domains:run')->dailyAt('00:35')->withoutOverlapping()
            ->description('Domains: renewal invoices, renewal reminders and expiry notices');
        $schedule->command('domains:sync')->dailyAt('04:10')->withoutOverlapping()
            ->description('Sync domain expiry dates and statuses with the registrars');
        $schedule->command('currencies:update-rates', ['--scheduled'])->dailyAt(self::EXCHANGE_RATES_TIME)->withoutOverlapping()
            ->description('Update exchange rates from the European Central Bank, when that is on under Settings > Automation');
        $schedule->command('backup:run', ['--scheduled'])->dailyAt(self::time((string) config('backups.time'), '01:30'))->withoutOverlapping()
            ->description('Back up the database, when automatic backups are on under Settings > Backups');
        if (Demo::enabled() && (int) config('demo.reset_hours') > 0) {
            $schedule->command('demo:reset')->cron('0 */'.min(23, (int) config('demo.reset_hours')).' * * *')->withoutOverlapping()
                ->description('Reset the demo data');
        }
        $schedule->command('activity:prune')->dailyAt('03:15')
            ->description('Delete old activity log entries');
        $schedule->command('model:prune', ['--model' => [InboundEmail::class, LoginAttempt::class, IpBan::class, KnownDevice::class, ScheduledTaskRun::class, WebhookDelivery::class, SmsLog::class]])->dailyAt('03:25')
            ->description('Delete old ticket email, logins, ended IP bans, forgotten browsers, task runs, webhook deliveries and texts');
    }

    /** The billing run's time of day, "HH:MM". */
    public static function billingRunTime(): string
    {
        return self::time((string) config('billing.run_time'), self::BILLING_RUN_TIME);
    }

    /** A saved "HH:MM", or the default when it isn't one. */
    private static function time(string $time, string $default): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : $default;
    }
}
