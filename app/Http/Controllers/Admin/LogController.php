<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Support\Demo;
use App\System\Logs\LogReader;
use App\System\PhpInfo;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Settings > Logs (the app's log files, with links to the other logs) and Settings > PHP info. */
class LogController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private LogReader $logs) {}

    public function index(): View
    {
        return view('admin.system.logs', [
            'files' => $this->logs->files(),
            'path' => $this->logs->path(),
        ]);
    }

    public function show(Request $request, string $name): View
    {
        Demo::refuse();
        $file = $this->read($name);

        $level = in_array($request->query('level'), LogReader::LEVELS, true) ? (string) $request->query('level') : null;
        $search = trim((string) $request->query('q'));
        $entries = collect($file['entries'])
            ->when($level, fn ($entries) => $entries->where('level', $level))
            ->when($search !== '', fn ($entries) => $entries->filter(fn (array $entry) => mb_stripos($entry['message']."\n".$entry['details'], $search) !== false))
            ->values();
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('admin.system.log', [
            'name' => $name,
            'size' => (int) filesize($this->logs->file($name)),
            'truncated' => $file['truncated'],
            'maxBytes' => $this->logs->maxBytes(),
            'levels' => LogReader::LEVELS,
            'counts' => collect($file['entries'])->countBy('level'),
            'level' => $level,
            'search' => $search,
            'entries' => (new LengthAwarePaginator($entries->forPage($page, self::PER_PAGE)->values(), $entries->count(), self::PER_PAGE, $page, [
                'path' => $request->url(),
            ]))->withQueryString(),
        ]);
    }

    public function download(string $name): BinaryFileResponse
    {
        Demo::refuse();

        try {
            $file = $this->logs->file($name);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        Activity::record('Downloaded a log file', null, ['file' => $name]);

        return response()->download($file, $name, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function php(PhpInfo $php): View
    {
        Demo::refuse();

        return view('admin.system.php', ['summary' => $php->summary(), 'phpinfo' => $php->html()]);
    }

    private function read(string $name): array
    {
        try {
            return $this->logs->read($name);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }
}
