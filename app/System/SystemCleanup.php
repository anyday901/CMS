<?php

namespace App\System;

use App\Models\Activity;
use App\Models\EmailLog;
use App\Models\GatewayLog;
use App\Models\InboundEmail;
use App\Models\LoginAttempt;
use App\Models\ScheduledTaskRun;
use App\Models\SmsLog;
use App\Models\TicketAttachment;
use App\Models\WebhookDelivery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Settings > Cleanup and `php artisan system:cleanup`: counts and deletes
 * logs and ticket attachments made before a date. Only logs and files are
 * listed here; invoices, transactions, credit notes, credit and every other
 * financial record are never touched.
 */
class SystemCleanup
{
    public const ATTACHMENTS = 'attachments';

    public const EMAILS = 'emails';

    public const ACTIVITY = 'activity';

    public const FAILED_JOBS = 'failed_jobs';

    /** What can be cleaned up, in the order the page lists it. */
    public const TARGETS = [
        self::ATTACHMENTS => 'Ticket attachments (the files and their records)',
        self::EMAILS => 'Email log',
        self::ACTIVITY => 'Activity log',
        'gateway_log' => 'Gateway log',
        'login_attempts' => 'Login history',
        'inbound_emails' => 'Ticket email log (piped and fetched email)',
        'webhook_deliveries' => 'Webhook deliveries',
        'sms_log' => 'Text message log',
        'task_runs' => 'Scheduled task runs',
        self::FAILED_JOBS => 'Failed queue jobs',
    ];

    private const CREATED = 'created_at';

    /** Each model target and the column that says when a row was made. */
    private const MODELS = [
        self::ATTACHMENTS => [TicketAttachment::class, self::CREATED],
        self::EMAILS => [EmailLog::class, self::CREATED],
        self::ACTIVITY => [Activity::class, self::CREATED],
        'gateway_log' => [GatewayLog::class, self::CREATED],
        'login_attempts' => [LoginAttempt::class, self::CREATED],
        'inbound_emails' => [InboundEmail::class, self::CREATED],
        'webhook_deliveries' => [WebhookDelivery::class, self::CREATED],
        'sms_log' => [SmsLog::class, self::CREATED],
        'task_runs' => [ScheduledTaskRun::class, 'started_at'],
    ];

    /** How many attachment rows are read at a time when their files are deleted. */
    private const CHUNK = 500;

    /**
     * How much each target would lose.
     *
     * @param  list<string>|null  $targets  null for all
     * @return array<string, int>
     */
    public function preview(CarbonInterface $before, ?array $targets = null): array
    {
        $counts = [];

        foreach ($this->keys($targets) as $target) {
            $counts[$target] = $this->query($target, $before)?->count() ?? 0;
        }

        return $counts;
    }

    /**
     * Deletes everything in the targets made before the date, and writes
     * what went to the activity log (after the activity log's own cleanup,
     * so that entry stays).
     *
     * @param  list<string>|null  $targets  null for all
     * @return array<string, int> how many were deleted, per target
     */
    public function run(CarbonInterface $before, ?array $targets = null): array
    {
        $deleted = [];

        foreach ($this->keys($targets) as $target) {
            $deleted[$target] = $target === self::ATTACHMENTS
                ? $this->deleteAttachments($before)
                : $this->query($target, $before)?->delete() ?? 0;
        }

        if (array_sum($deleted) > 0) {
            Activity::record('Cleaned up old data from before '.$before->toDateString(), null, [
                'before' => $before->toDateTimeString(),
                'deleted' => array_filter($deleted),
            ]);
        }

        return $deleted;
    }

    /**
     * @param  list<string>|null  $targets
     * @return list<string>
     */
    private function keys(?array $targets): array
    {
        if ($targets === null) {
            return array_keys(self::TARGETS);
        }

        $unknown = array_diff($targets, array_keys(self::TARGETS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown cleanup target: '.implode(', ', $unknown));
        }

        // Keep the page's order, whatever order they were asked for in.
        return array_values(array_intersect(array_keys(self::TARGETS), $targets));
    }

    /** The rows of a target made before the date; null when its table isn't there. */
    private function query(string $target, CarbonInterface $before): EloquentBuilder|Builder|null
    {
        if ($target === self::FAILED_JOBS) {
            return $this->failedJobs($before);
        }

        [$model, $column] = self::MODELS[$target];

        return $model::where($column, '<', $before);
    }

    private function failedJobs(CarbonInterface $before): ?Builder
    {
        $table = (string) config('queue.failed.table', self::FAILED_JOBS);

        return Schema::hasTable($table) ? DB::table($table)->where('failed_at', '<', $before) : null;
    }

    /** Deletes the files on disk, then their rows, a chunk at a time. */
    private function deleteAttachments(CarbonInterface $before): int
    {
        $deleted = 0;

        do {
            $chunk = TicketAttachment::where(self::CREATED, '<', $before)->orderBy('id')->limit(self::CHUNK)->get(['id', 'path']);
            Storage::disk('local')->delete($chunk->pluck('path')->filter()->all());
            $deleted += TicketAttachment::whereIn('id', $chunk->pluck('id'))->delete();
        } while ($chunk->count() === self::CHUNK);

        return $deleted;
    }
}
