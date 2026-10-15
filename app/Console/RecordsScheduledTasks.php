<?php

namespace App\Console;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notes each run of an automatic task, so the cron status page can say
 * when each last ran and whether it worked. A database problem here never
 * stops the task itself.
 */
class RecordsScheduledTasks
{
    /** @var array<int, int> scheduled event object id => its run's id */
    private static array $running = [];

    public static function listen(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            self::quietly(function () use ($event) {
                self::$running[spl_object_id($event->task)] = ScheduledTaskRun::create([
                    'task' => ScheduledTaskRun::taskName($event->task),
                    'started_at' => now(),
                    'status' => ScheduledTaskRun::RUNNING,
                ])->id;
            });
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $code = $event->task->exitCode;
            self::finish($event->task, $code === null || $code === 0 ? ScheduledTaskRun::SUCCEEDED : ScheduledTaskRun::FAILED, $code === null || $code === 0 ? null : "Exited with code {$code}.", $code);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            self::finish($event->task, ScheduledTaskRun::FAILED, Str::limit($event->exception->getMessage(), 2000), $event->task->exitCode);
        });

        Event::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            self::quietly(fn () => ScheduledTaskRun::create([
                'task' => ScheduledTaskRun::taskName($event->task),
                'started_at' => now(),
                'finished_at' => now(),
                'status' => ScheduledTaskRun::SKIPPED,
            ]));
        });
    }

    private static function finish(object $task, string $status, ?string $error, ?int $code): void
    {
        $id = self::$running[spl_object_id($task)] ?? null;

        if ($id === null) {
            return;
        }

        self::quietly(function () use ($id, $status, $error, $code) {
            $run = ScheduledTaskRun::find($id);
            // A failure is reported after the finish; keep the failure.
            if ($run === null || $run->status === ScheduledTaskRun::FAILED) {
                return;
            }
            $run->update(['finished_at' => now(), 'status' => $status, 'error' => $error, 'exit_code' => $code]);
        });
    }

    private static function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
