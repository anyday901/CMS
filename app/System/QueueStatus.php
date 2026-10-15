<?php

namespace App\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Jobs waiting for the queue worker and jobs that failed. */
final class QueueStatus
{
    /**
     * Pending jobs are only counted for the database queue.
     *
     * @return array{driver: string, pending: int|null, oldest: Carbon|null, failed: int|null}
     */
    public static function read(): array
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
