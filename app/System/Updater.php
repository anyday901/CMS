<?php

namespace App\System;

use App\Models\Activity;
use App\System\Backups\BackupManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Throwable;
use ZipArchive;

/**
 * The one-click updater: downloads a release, checks its checksum, backs up
 * the database and the current files, puts the site in maintenance mode,
 * copies the new files in, migrates the database and clears the caches. If
 * copying or migrating fails, it puts the old files and database back.
 */
class Updater
{
    /** Paths in the install that an update never touches. */
    public const PRESERVE = ['.env', '.git', 'storage', 'extensions', 'themes/overrides', 'public/storage', 'node_modules'];

    /** Folders swapped whole rather than copied over, so nothing stale is left in them. */
    private const SWAP = ['vendor', 'public/build'];

    private string $root;

    public function __construct(private BackupManager $backups, private UpdateStatus $status, ?string $root = null)
    {
        $this->root = rtrim($root ?? base_path(), '/');
    }

    /** Why the one-click update can't run on this install, or null when it can. */
    public function blocker(): ?string
    {
        return match (true) {
            ! config('updates.enabled') => 'The updater is turned off (UPDATES_ENABLED=false).',
            is_dir($this->root.'/.git') || Version::isDevelopment() => 'This copy runs from a git checkout, so update it with git instead (see below).',
            ! class_exists(ZipArchive::class) => 'PHP\'s zip extension is missing, and the updater needs it to unpack releases.',
            ! is_writable($this->root) || ! is_writable($this->root.'/vendor') => 'The web server can\'t write to '.$this->root.', so it can\'t replace the files. Update from the command line as the owner of the files instead.',
            default => null,
        };
    }

    /** @param  array{version: string, download_url: string|null, checksum_url: string|null}  $release */
    public function update(array $release): void
    {
        $version = $release['version'];
        $work = storage_path('app/updates');
        $staging = $work.'/staging-'.$version;
        $previous = Version::current();
        $swapped = [];
        $migrating = false;
        $backup = null;
        $down = false;

        File::ensureDirectoryExists($work, 0700);
        $this->status->start($version);

        try {
            if (blank($release['download_url']) || blank($release['checksum_url'])) {
                throw new RuntimeException("Release {$version} has no ".config('updates.asset').' or checksum to download.');
            }

            $this->status->step('Downloading '.$version);
            $zip = $this->download($release, $work);

            $this->status->step('Unpacking');
            $source = $this->unpack($zip, $staging, $version);

            $this->status->step('Backing up the database');
            $backup = $this->backups->create("before updating to {$version}");

            $this->status->step('Saving a copy of the current files');
            $before = $this->snapshot($work.'/before-'.$previous.'.zip');

            $this->artisan(['down', '--retry=60', '--refresh=15']);
            $down = true;

            try {
                $this->status->step('Copying the new files');
                $swapped = $this->install($source);

                $this->status->step('Updating the database');
                $migrating = true;
                $this->artisan(['migrate', '--force']);
                $migrating = false;
                $this->artisan(['optimize:clear']);
            } catch (Throwable $e) {
                $this->status->step('Something went wrong; putting the old version back');
                $this->rollback($before, $swapped, $migrating ? $backup : null);

                throw $e;
            }

            foreach ($swapped as $old) {
                File::deleteDirectory($old);
            }

            Activity::record("Updated CMS from {$previous} to {$version}", null, ['backup' => $backup]);
            $this->status->finish("Updated to {$version}.");
        } catch (Throwable $e) {
            Activity::record("Updating CMS to {$version} failed", null, ['error' => $e->getMessage()]);
            $this->status->fail($e->getMessage());

            throw $e;
        } finally {
            if ($down) {
                $this->bringBackUp();
            }
            File::deleteDirectory($staging);
            @unlink($work.'/cms-'.$version.'.zip');
        }
    }

    /** Leaves maintenance mode however the update went, and restarts the queue workers on the new code. */
    private function bringBackUp(): void
    {
        foreach ([['up'], ['queue:restart']] as $arguments) {
            try {
                $this->artisan($arguments);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** Downloads the release zip and checks it against its published SHA-256. */
    private function download(array $release, string $work): string
    {
        $zip = $work.'/cms-'.$release['version'].'.zip';

        $expected = strtolower(strtok(trim(Http::timeout(30)->get($release['checksum_url'])->throw()->body()), " \t\n") ?: '');
        Http::timeout(600)->sink($zip)->get($release['download_url'])->throw();

        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! hash_equals($expected, hash_file('sha256', $zip))) {
            @unlink($zip);

            throw new RuntimeException('The download doesn\'t match its checksum, so it wasn\'t installed. Try again later.');
        }

        return $zip;
    }

    /** Unpacks the zip and returns the folder holding the app (the one with artisan in it). */
    private function unpack(string $zip, string $staging, string $version): string
    {
        File::deleteDirectory($staging);
        Archive::extract($zip, $staging);

        $source = is_file($staging.'/artisan') ? $staging : collect(File::directories($staging))->first(fn (string $dir) => is_file($dir.'/artisan'));

        if ($source === null || ! is_file($source.'/vendor/autoload.php')) {
            throw new RuntimeException('The download isn\'t a complete CMS release (it has no artisan or vendor folder).');
        }

        $packaged = is_file($source.'/VERSION') ? trim((string) file_get_contents($source.'/VERSION')) : '';
        if (Version::clean($packaged) !== Version::clean($version)) {
            throw new RuntimeException("The download says it is version {$packaged}, not {$version}.");
        }

        return $source;
    }

    /** Zips the current files (without the preserved paths and swapped folders) to put back if the update fails. */
    private function snapshot(string $path): string
    {
        @unlink($path);
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Can't write {$path}.");
        }

        foreach ($this->files($this->root, [...self::PRESERVE, ...self::SWAP]) as $relative) {
            $zip->addFile($this->root.'/'.$relative, $relative);
        }

        $zip->close();

        return $path;
    }

    /**
     * Swaps in the new vendor and build folders and copies everything else over.
     *
     * @return array<string, string> swapped folder => where the old one was moved
     */
    private function install(string $source): array
    {
        $swapped = [];

        foreach (self::SWAP as $folder) {
            if (! is_dir($source.'/'.$folder)) {
                continue;
            }

            $old = $this->root.'/'.$folder.'.old-'.time();
            if (is_dir($this->root.'/'.$folder)) {
                rename($this->root.'/'.$folder, $old);
                $swapped[$folder] = $old;
            }
            if (! @rename($source.'/'.$folder, $this->root.'/'.$folder)) {
                File::copyDirectory($source.'/'.$folder, $this->root.'/'.$folder);
            }
        }

        foreach ($this->files($source, [...self::PRESERVE, ...self::SWAP]) as $relative) {
            $target = $this->root.'/'.$relative;
            File::ensureDirectoryExists(dirname($target));
            // Copy then rename, so PHP never reads a half-written file.
            copy($source.'/'.$relative, $target.'.updating');
            rename($target.'.updating', $target);
        }

        return $swapped;
    }

    /** @param  array<string, string>  $swapped */
    private function rollback(string $before, array $swapped, ?string $backup): void
    {
        foreach ($swapped as $folder => $old) {
            File::deleteDirectory($this->root.'/'.$folder);
            rename($old, $this->root.'/'.$folder);
        }

        Archive::extract($before, $this->root);

        if ($backup !== null) {
            $this->backups->restore($backup);
        }
    }

    /**
     * Files under $root, relative to it, leaving out the given paths.
     *
     * @param  list<string>  $skip
     * @return list<string>
     */
    private function files(string $root, array $skip): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');

            if ($file->isFile() && ! $this->skipped($relative, $skip)) {
                $files[] = $relative;
            }
        }

        return $files;
    }

    /** @param  list<string>  $skip */
    private function skipped(string $relative, array $skip): bool
    {
        foreach ($skip as $path) {
            if ($relative === $path || str_starts_with($relative, $path.'/') || str_starts_with($relative, $path.'.old-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs artisan in a new PHP process, so it loads the new code rather
     * than what this one already has in memory.
     *
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments): void
    {
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $result = Process::path($this->root)->timeout(1800)->run([$php, 'artisan', ...$arguments, '--no-interaction']);

        if ($result->failed()) {
            throw new RuntimeException('php artisan '.$arguments[0].' failed: '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
