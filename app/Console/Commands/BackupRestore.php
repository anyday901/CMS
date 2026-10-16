<?php

namespace App\Console\Commands;

use App\System\Backups\BackupManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BackupRestore extends Command
{
    protected $signature = 'backup:restore {file? : The backup\'s file name; leave out to pick from a list} {--force : Don\'t ask before replacing the database}';

    protected $description = 'Replace the database with one of the backups made under Settings > Backups';

    public function handle(BackupManager $backups): int
    {
        $name = $this->pick($backups);

        if ($name === null || (! $this->option('force') && ! $this->confirm("Replace everything in the database with {$name}? Anything since that backup is lost."))) {
            return self::FAILURE;
        }

        $this->call('down', ['--retry' => 60]);

        try {
            $count = $backups->restore($name);
        } finally {
            $this->call('up');
        }

        $this->call('cache:clear');
        $this->info("Restored {$name} ({$count} statements). Run php artisan migrate if it came from an older version.");

        return self::SUCCESS;
    }

    /** The backup named on the command line, or picked from a list; null (with an error shown) when there is none. */
    private function pick(BackupManager $backups): ?string
    {
        $names = $backups->all()->pluck('name');

        if ($names->isEmpty()) {
            $this->error("There are no backups in {$backups->path()}.");

            return null;
        }

        $name = $this->argument('file') ?? $this->choice('Which backup?', $names->all(), 0);

        try {
            $backups->file($name);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        return $name;
    }
}
