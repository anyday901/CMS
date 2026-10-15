<?php

namespace App\Console\Commands;

use App\System\UpdateChecker;
use App\System\Updater;
use App\System\Version;
use Illuminate\Console\Command;
use Throwable;

class UpdateCms extends Command
{
    protected $signature = 'cms:update {--check : Only say whether there is a newer release} {--force : Don\'t ask first}';

    protected $description = 'Update CMS to the newest release: download, back up, copy the new files, migrate';

    public function handle(UpdateChecker $checker, Updater $updater): int
    {
        $release = $checker->latest(fresh: true);

        if ($release === null) {
            $this->error('Couldn\'t reach the releases on GitHub ('.config('updates.repository').').');

            return self::FAILURE;
        }

        if (! Version::newer($release['version'])) {
            $this->info('CMS '.Version::current().' is up to date.');

            return self::SUCCESS;
        }

        $this->info("Version {$release['version']} is out; this is ".Version::current().". Release notes: {$release['url']}");

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if ($blocker = $updater->blocker()) {
            $this->error($blocker);

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Update now? The site is in maintenance mode for a minute or so.', true)) {
            return self::FAILURE;
        }

        try {
            $updater->update($release);
        } catch (Throwable $e) {
            $this->error('The update didn\'t finish, so the old version is still running: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Updated to {$release['version']}.");

        return self::SUCCESS;
    }
}
