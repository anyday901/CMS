<?php

namespace App\Jobs;

use App\System\UpdateChecker;
use App\System\Updater;
use App\System\UpdateStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** An update started from Settings > Updates, run by the queue worker. */
class RunUpdate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function handle(UpdateChecker $checker, Updater $updater, UpdateStatus $status): void
    {
        $release = $checker->latest(fresh: true);

        if ($release === null) {
            // The page is waiting on this update, so say why it stopped.
            $status->fail('Couldn\'t reach GitHub to download the update. Nothing was changed; try again in a few minutes.');

            return;
        }

        $updater->update($release);
    }
}
