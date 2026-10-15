<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunUpdate;
use App\Models\Activity;
use App\Support\Demo;
use App\Support\Settings;
use App\System\HealthCheck;
use App\System\UpdateChecker;
use App\System\Updater;
use App\System\UpdateStatus;
use App\System\Version;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Settings > System health, Maintenance and Updates. */
class SystemController extends Controller
{
    public function health(HealthCheck $health): View
    {
        $checks = $health->run();

        return view('admin.system.health', [
            'checks' => $checks,
            'overall' => HealthCheck::overall($checks),
            'version' => Version::current(),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    }

    public function maintenance(): View
    {
        return view('admin.system.maintenance', [
            'enabled' => (bool) config('maintenance.enabled'),
            'message' => config('maintenance.message'),
        ]);
    }

    public function updateMaintenance(Request $request): RedirectResponse
    {
        Demo::refuse();
        $data = $request->validate(['enabled' => ['boolean'], 'message' => ['nullable', 'string', 'max:2000']]);

        Settings::save(['maintenance.enabled' => $request->boolean('enabled'), 'maintenance.message' => $data['message'] ?? null]);
        Activity::record($request->boolean('enabled') ? 'Turned maintenance mode on' : 'Turned maintenance mode off');

        return redirect()->route('admin.maintenance.edit')->with('status', $request->boolean('enabled')
            ? 'Maintenance mode is on. Visitors see the notice; you can still look around the portal while signed in here.'
            : 'Maintenance mode is off.');
    }

    public function updates(UpdateChecker $checker, Updater $updater, UpdateStatus $status): View
    {
        $release = $checker->latest();

        return view('admin.system.updates', [
            'version' => Version::current(),
            'release' => $release,
            'newer' => $release !== null && Version::newer($release['version']),
            'notes' => $release ? Str::markdown($release['notes'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) : null,
            'blocker' => $updater->blocker(),
            'sync' => config('queue.default') === 'sync',
            'status' => $status->read(),
            'running' => $status->running(),
            'repository' => config('updates.repository'),
        ]);
    }

    public function checkUpdates(UpdateChecker $checker): RedirectResponse
    {
        $release = $checker->latest(fresh: true);

        return redirect()->route('admin.updates.index')->with('status', match (true) {
            $release === null => 'Couldn\'t reach the releases on GitHub. Try again later.',
            Version::newer($release['version']) => "Version {$release['version']} is available.",
            default => 'You have the newest version.',
        });
    }

    public function runUpdate(UpdateChecker $checker, Updater $updater, UpdateStatus $status): RedirectResponse
    {
        Demo::refuse();
        $release = $checker->latest(fresh: true);

        abort_if($release === null || ! Version::newer($release['version']), 409, 'There is no newer version to install.');

        if (($blocker = $updater->blocker()) !== null || config('queue.default') === 'sync' || $status->running()) {
            return back()->withErrors(['update' => $blocker ?? 'Run php artisan cms:update on the server.']);
        }

        $status->start($release['version']);
        RunUpdate::dispatch();
        Activity::record("Started updating CMS to {$release['version']}");

        return redirect()->route('admin.updates.index')->with('status', 'The update has started. This page shows how it is going; the site is in maintenance mode for a minute or so while the files are copied.');
    }
}
