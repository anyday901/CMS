<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Demo;
use App\System\SystemCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Settings > Cleanup: delete old logs and ticket attachments, after a count of what would go. */
class CleanupController extends Controller
{
    /** The date picked when none is given: three months back, like WHMCS. */
    private const DEFAULT_MONTHS = 3;

    private const BEFORE = 'before';

    private const TARGETS = 'targets';

    private const DATE = ['date_format:Y-m-d', 'before_or_equal:today'];

    public function __construct(private SystemCleanup $cleanup) {}

    public function show(Request $request): View
    {
        $data = $request->validate([self::BEFORE => ['nullable', ...self::DATE]]);
        $before = isset($data[self::BEFORE])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data[self::BEFORE])
            : CarbonImmutable::today()->subMonthsNoOverflow(self::DEFAULT_MONTHS);

        return view('admin.system.cleanup', [
            self::BEFORE => $before,
            self::TARGETS => SystemCleanup::TARGETS,
            'counts' => $this->cleanup->preview($before),
            'retentionDays' => config('activity.retention_days'),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        Demo::refuse();
        $data = $request->validate([
            self::BEFORE => ['required', ...self::DATE],
            self::TARGETS => ['required', 'array', 'min:1'],
            self::TARGETS.'.*' => ['string', Rule::in(array_keys(SystemCleanup::TARGETS))],
        ], [self::TARGETS.'.required' => 'Tick at least one thing to clean up.']);

        $before = CarbonImmutable::createFromFormat('!Y-m-d', $data[self::BEFORE]);
        $deleted = $this->cleanup->run($before, array_values(array_unique($data[self::TARGETS])));
        $total = array_sum($deleted);

        return redirect()->route('admin.cleanup.show', [self::BEFORE => $data[self::BEFORE]])->with('status', $total === 0
            ? 'Nothing to delete from before that date.'
            : "Deleted {$total} item".($total === 1 ? '' : 's').' from before '.$before->toFormattedDateString().'.');
    }
}
