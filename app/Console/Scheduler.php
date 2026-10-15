<?php

namespace App\Console;

use App\Models\InboundEmail;
use App\Models\IpBan;
use App\Models\KnownDevice;
use App\Models\LoginAttempt;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Every automatic task and when it runs. routes/console.php hands it the
 * scheduler; the cron status page reads it to list the tasks.
 */
class Scheduler
{
    /** The billing run's time of day when none is set under Automation. */
    public const BILLING_RUN_TIME = '00:05';

    public static function define(Schedule $schedule): void
    {
        $schedule->command('billing:run')->dailyAt(self::billingRunTime())->withoutOverlapping()
            ->description('Billing run: cancellations, renewal invoices, late fees, reminders, suspensions and terminations');
        $schedule->command('usage:collect')->hourlyAt(50)->withoutOverlapping()
            ->description('Read disk and bandwidth use from servers');
        $schedule->command('tickets:escalate')->everyFiveMinutes()->withoutOverlapping()
            ->description('Apply ticket escalation rules');
        $schedule->command('tickets:close-inactive')->everyFifteenMinutes()->withoutOverlapping()
            ->description('Close inactive tickets');
        $schedule->command('servers:check')->everyFiveMinutes()->withoutOverlapping()
            ->description('Check that monitored servers answer');
        $schedule->command('activity:prune')->dailyAt('03:15')
            ->description('Delete old activity log entries');
        $schedule->command('model:prune', ['--model' => [InboundEmail::class, LoginAttempt::class, IpBan::class, KnownDevice::class, ScheduledTaskRun::class]])->dailyAt('03:25')
            ->description('Delete old ticket email, logins, ended IP bans, forgotten browsers and task runs');
    }

    /** The billing run's time of day, "HH:MM". */
    public static function billingRunTime(): string
    {
        $time = (string) config('billing.run_time');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::BILLING_RUN_TIME;
    }
}
