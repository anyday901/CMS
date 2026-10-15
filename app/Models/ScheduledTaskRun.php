<?php

namespace App\Models;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One run of an automatic task by the scheduler, kept 30 days for the cron status page. */
class ScheduledTaskRun extends Model
{
    use MassPrunable;

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const KEEP_DAYS = 30;

    public $timestamps = false;

    protected $fillable = ['task', 'started_at', 'finished_at', 'status', 'exit_code', 'error'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'exit_code' => 'integer'];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /** The artisan command a scheduled event runs, such as "billing:run", or its description for a closure. */
    public static function taskName(Event $event): string
    {
        $command = (string) $event->command;

        if ($command === '') {
            return Str::limit((string) ($event->description ?: 'Closure'), 250, '');
        }

        $name = trim(Str::after(str_replace(["'", '"'], '', $command), 'artisan'));

        return Str::limit(Str::before($name.' ', ' '), 250, '');
    }

    /** How long it took, in seconds. */
    public function seconds(): ?float
    {
        return $this->finished_at ? round($this->started_at->diffInMilliseconds($this->finished_at) / 1000, 1) : null;
    }
}
