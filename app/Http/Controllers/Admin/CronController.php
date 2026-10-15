<?php

namespace App\Http\Controllers\Admin;

use App\Console\Scheduler;
use App\Http\Controllers\Controller;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/** Whether the cron entry is running the scheduler, and how each automatic task last went. */
class CronController extends Controller
{
    /** The scheduler counts as stopped when no task has started for this long; some run every five minutes. */
    public const STALE_MINUTES = 15;

    public function __invoke(): View
    {
        $schedule = new Schedule(config('app.timezone'));
        Scheduler::define($schedule);

        $runs = ScheduledTaskRun::query();
        $lastSeen = (clone $runs)->max('started_at');

        $tasks = collect($schedule->events())->map(function (Event $event) {
            $name = ScheduledTaskRun::taskName($event);

            return [
                'name' => $name,
                'description' => $event->description,
                'expression' => $event->expression,
                'next' => $event->nextRunDate(),
                'last' => ScheduledTaskRun::where('task', $name)->where('status', '!=', ScheduledTaskRun::SKIPPED)->latest('started_at')->latest('id')->first(),
                'lastSuccess' => ScheduledTaskRun::where('task', $name)->where('status', ScheduledTaskRun::SUCCEEDED)->max('started_at'),
                'failures' => ScheduledTaskRun::where('task', $name)->where('status', ScheduledTaskRun::FAILED)->where('started_at', '>=', now()->subDay())->count(),
            ];
        });

        return view('admin.cron.show', [
            'tasks' => $tasks,
            'lastSeen' => $lastSeen ? now()->parse($lastSeen) : null,
            'stale' => $lastSeen === null || now()->parse($lastSeen)->lt(now()->subMinutes(self::STALE_MINUTES)),
            'recent' => ScheduledTaskRun::latest('started_at')->latest('id')->limit(50)->get(),
            'cronLine' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            'queue' => $this->queue(),
        ]);
    }

    /**
     * Jobs waiting for the queue worker and jobs that failed, when the
     * database queue is used.
     *
     * @return array{driver: string, pending: int|null, oldest: Carbon|null, failed: int|null}
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');
        $pending = null;
        $oldest = null;

        if ($driver === 'database' && Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')->count();
            $oldest = ($at = DB::table('jobs')->min('created_at')) ? now()->createFromTimestamp((int) $at) : null;
        }

        return [
            'driver' => $driver,
            'pending' => $pending,
            'oldest' => $oldest,
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null,
        ];
    }
}
