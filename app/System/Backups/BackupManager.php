<?php

namespace App\System\Backups;

use App\Models\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Database backups under Settings > Backups: makes them, keeps the newest
 * few, copies them to another disk when one is set, and restores them.
 */
class BackupManager
{
    /** Names this class writes, and the only ones it will read or delete. */
    private const NAME = '/^backup-\d{4}-\d{2}-\d{2}-\d{6}(-\d+)?\.sql\.gz$/';

    public const SCHEDULES = ['off' => 'Off', 'daily' => 'Every day', 'weekly' => 'Every week (on Sundays)'];

    public function __construct(private DatabaseDumper $dumper) {}

    public function path(): string
    {
        return (string) config('backups.path');
    }

    /** @return Collection<int, array{name: string, size: int, created_at: Carbon}> newest first */
    public function all(): Collection
    {
        if (! is_dir($this->path())) {
            return collect();
        }

        return collect(File::files($this->path()))
            ->filter(fn ($file) => preg_match(self::NAME, $file->getFilename()))
            ->map(fn ($file) => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'created_at' => Carbon::createFromTimestamp($file->getMTime()),
            ])
            ->sortByDesc(fn (array $backup) => $backup['name'])
            ->values();
    }

    public function latest(): ?array
    {
        return $this->all()->first();
    }

    /** Backs up the database and returns the file's name. Old ones beyond the number to keep are deleted. */
    public function create(string $reason = 'by hand'): string
    {
        File::ensureDirectoryExists($this->path(), 0700);
        $this->protect();

        $name = 'backup-'.now()->format('Y-m-d-His').'.sql.gz';
        for ($i = 2; is_file($this->path().'/'.$name); $i++) {
            $name = 'backup-'.now()->format('Y-m-d-His')."-{$i}.sql.gz";
        }

        $partial = $this->path().'/.'.$name.'.part';

        try {
            $tables = $this->dumper->dump($partial);
            rename($partial, $this->path().'/'.$name);
            @chmod($this->path().'/'.$name, 0600);
        } catch (Throwable $e) {
            @unlink($partial);
            Activity::record('A database backup failed', null, ['error' => $e->getMessage(), 'reason' => $reason]);

            throw $e;
        }

        $copied = $this->copyToDisk($name);
        $this->prune();
        Activity::record("Backed up the database ({$reason})", null, ['file' => $name, 'tables' => $tables, 'copied_to' => $copied]);

        return $name;
    }

    /** The full path of a backup, after checking it is one of ours. */
    public function file(string $name): string
    {
        if (! preg_match(self::NAME, $name) || ! is_file($this->path().'/'.$name)) {
            throw new InvalidArgumentException('There is no backup called '.$name.'.');
        }

        return $this->path().'/'.$name;
    }

    public function delete(string $name): void
    {
        unlink($this->file($name));
        Activity::record('Deleted a database backup', null, ['file' => $name]);
    }

    /** Deletes the oldest backups beyond the number to keep. */
    public function prune(): int
    {
        $keep = max(1, (int) config('backups.keep'));
        $old = $this->all()->slice($keep);
        $old->each(fn (array $backup) => @unlink($this->path().'/'.$backup['name']));

        return $old->count();
    }

    /**
     * Loads a backup into the database, replacing what's there. Backups put
     * each statement on its own line, so it runs them one line at a time.
     */
    public function restore(string $name): int
    {
        $in = gzopen($this->file($name), 'rb');
        if ($in === false) {
            throw new RuntimeException("Can't read {$name}.");
        }

        $statements = 0;
        // Already inside a transaction (such as a test's), the backup's own BEGIN and COMMIT are left out.
        $nested = DB::transactionLevel() > 0;

        try {
            while (($line = gzgets($in)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '' || str_starts_with($line, '--') || ($nested && in_array($line, ['BEGIN TRANSACTION;', 'COMMIT;'], true))) {
                    continue;
                }

                DB::unprepared($line);
                $statements++;
            }
        } finally {
            gzclose($in);
        }

        return $statements;
    }

    /** Whether automatic backups run today, by the schedule setting. */
    public static function dueToday(): bool
    {
        return match ((string) config('backups.schedule')) {
            'daily' => true,
            'weekly' => now()->isSunday(),
            default => false,
        };
    }

    /** @return string|null the disk it was copied to */
    private function copyToDisk(string $name): ?string
    {
        $disk = config('backups.disk');

        if (blank($disk)) {
            return null;
        }

        $stream = fopen($this->path().'/'.$name, 'rb');

        try {
            Storage::disk($disk)->writeStream('backups/'.$name, $stream);
        } catch (Throwable $e) {
            // The local copy is still there; say so in the log rather than lose the backup.
            Activity::record("Couldn't copy a database backup to the {$disk} disk", null, ['file' => $name, 'error' => $e->getMessage()]);

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $disk;
    }

    /** Keeps the folder out of reach if it is ever under the web root. */
    private function protect(): void
    {
        $htaccess = $this->path().'/.htaccess';

        if (! is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
    }
}
