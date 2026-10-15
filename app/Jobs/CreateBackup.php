<?php

namespace App\Jobs;

use App\System\Backups\BackupManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A database backup started from Settings > Backups, made by the queue worker. */
class CreateBackup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public string $reason = 'by hand') {}

    public function handle(BackupManager $backups): void
    {
        $backups->create($this->reason);
    }
}
