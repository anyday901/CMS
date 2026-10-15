<?php

namespace App\Reports\Support;

use App\Enums\TicketStatus;
use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Illuminate\Support\Facades\DB;

/** Tickets opened and closed each month and in each department. */
class TicketVolume extends Report
{
    private const COUNT = 'COUNT(*)';

    public function key(): string
    {
        return 'tickets-opened-and-closed';
    }

    public function title(): string
    {
        return 'Tickets opened and closed';
    }

    public function group(): string
    {
        return ReportRegistry::SUPPORT;
    }

    public function description(): string
    {
        return 'Tickets opened and closed each month and in each department, and how many of them are still open.';
    }

    public function filters(): array
    {
        return [self::DATES];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $between = $filters->between();
        $tickets = fn () => DB::table('tickets')->whereNull('merged_into_id');
        $closed = TicketStatus::Closed->value;

        $month = Sql::month('created_at');
        $opened = $tickets()->whereBetween('created_at', $between)->groupBy(DB::raw($month))
            ->selectRaw("{$month} as month, ".self::COUNT.' as opened, SUM(CASE WHEN status != ? THEN 1 ELSE 0 END) as still_open', [$closed])
            ->get()->keyBy('month');
        $month = Sql::month('closed_at');
        $closedBy = Sql::by($tickets()->whereBetween('closed_at', $between), $month, self::COUNT);

        $table = new ReportTable([
            'month' => ['Month', ReportTable::MONTH],
            'opened' => ['Opened', ReportTable::NUMBER],
            'closed' => ['Closed', ReportTable::NUMBER],
            'still_open' => ['Opened and still open', ReportTable::NUMBER],
        ]);
        $table->chart = 'opened';
        foreach (Periods::months($filters->from, $filters->to) as $key) {
            $table->add(['month' => $key, 'opened' => (int) ($opened->get($key)->opened ?? 0), 'closed' => (int) ($closedBy[$key] ?? 0), 'still_open' => (int) ($opened->get($key)->still_open ?? 0)]);
        }

        $departments = new ReportTable([
            'department' => ['Department', ReportTable::TEXT],
            'opened' => ['Opened', ReportTable::NUMBER],
            'closed' => ['Closed', ReportTable::NUMBER],
            'open_now' => ['Open now', ReportTable::NUMBER],
        ], 'By department');
        $openedBy = Sql::by($tickets()->whereBetween('created_at', $between), 'department_id', self::COUNT);
        $closedIn = Sql::by($tickets()->whereBetween('closed_at', $between), 'department_id', self::COUNT);
        $openNow = Sql::by($tickets()->where('status', '!=', $closed), 'department_id', self::COUNT);
        foreach (DB::table('departments')->orderBy('name')->pluck('name', 'id') as $id => $name) {
            $departments->add(['department' => $name, 'opened' => (int) ($openedBy[$id] ?? 0), 'closed' => (int) ($closedIn[$id] ?? 0), 'open_now' => (int) ($openNow[$id] ?? 0)]);
        }

        return (new ReportResult)
            ->table($table->withTotals(['month' => null]))
            ->table($departments->withTotals(['department' => 'Total']))
            ->note('Tickets merged into another one are counted once, as the ticket they were merged into.');
    }
}
