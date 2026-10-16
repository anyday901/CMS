<?php

namespace App\Console\Commands;

use App\System\Backups\BackupManager;
use Illuminate\Console\Command;
use Throwable;

class BackupRun extends Command
{
    protected $signature = 'backup:run {--scheduled : Only back up when the schedule under Settings > Backups says so today}';

    protected $description = 'Back up the database to storage/app/backups (and the backup disk, when one is set)';

    public function handle(BackupManager $backups): int
    {
        if ($this->option('scheduled') && ! BackupManager::dueToday()) {
            $this->info('No automatic backup today; see Settings > Backups.');

            return self::SUCCESS;
        }

        try {
            $name = $backups->create($this->option('scheduled') ? 'automatic' : 'from the command line');
        } catch (Throwable $e) {
            $this->error('The backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backed up the database to {$backups->path()}/{$name}.");

        return self::SUCCESS;
    }
}
