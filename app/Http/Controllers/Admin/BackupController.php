<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackup;
use App\Models\Activity;
use App\Support\Demo;
use App\Support\Settings;
use App\System\Backups\BackupManager;
use App\System\Backups\DatabaseDumper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Settings > Backups: database backups made by hand or on a schedule. */
class BackupController extends Controller
{
    public function __construct(private BackupManager $backups) {}

    public function index(): View
    {
        return view('admin.system.backups', [
            'backups' => $this->backups->all(),
            'path' => $this->backups->path(),
            'supported' => DatabaseDumper::supports(DB::connection()->getDriverName()),
            'driver' => DB::connection()->getDriverName(),
            'schedules' => BackupManager::SCHEDULES,
            'schedule' => config('backups.schedule'),
            'time' => config('backups.time'),
            'keep' => config('backups.keep'),
            'disk' => config('backups.disk'),
            'disks' => array_keys((array) config('filesystems.disks')),
        ]);
    }

    public function store(): RedirectResponse
    {
        Demo::refuse();
        CreateBackup::dispatch('by '.auth()->user()->name);

        return redirect()->route('admin.backups.index')->with('status', config('queue.default') === 'sync'
            ? 'Database backed up.'
            : 'Backup started. It shows in the list below once the queue worker has made it; reload the page in a minute.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'schedule' => ['required', Rule::in(array_keys(BackupManager::SCHEDULES))],
            'time' => ['required', 'date_format:H:i'],
            'keep' => ['required', 'integer', 'min:1', 'max:365'],
            'disk' => ['nullable', Rule::in(array_keys((array) config('filesystems.disks')))],
        ]);

        Settings::save([
            'backups.schedule' => $data['schedule'],
            'backups.time' => $data['time'],
            'backups.keep' => (int) $data['keep'],
            'backups.disk' => $data['disk'] ?? null,
        ]);
        Activity::record('Changed the backup settings', null, $data);

        return redirect()->route('admin.backups.index')->with('status', 'Backup settings saved.');
    }

    public function download(string $name): BinaryFileResponse
    {
        Demo::refuse();

        try {
            $file = $this->backups->file($name);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        Activity::record('Downloaded a database backup', null, ['file' => $name]);

        return response()->download($file, $name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(string $name): RedirectResponse
    {
        Demo::refuse();

        try {
            $this->backups->delete($name);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return redirect()->route('admin.backups.index')->with('status', "Deleted {$name}.");
    }
}
