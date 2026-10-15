<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Reports\Cell;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Reports menu, each report with its filters, and its CSV download. */
class ReportController extends Controller
{
    public function __construct(private ReportRegistry $reports) {}

    public function index(): View
    {
        return view('admin.reports.index', ['groups' => $this->reports->grouped()]);
    }

    public function show(Request $request, string $report): View
    {
        $report = $this->report($report);
        $filters = ReportFilters::fromRequest($request, $report);

        return view('admin.reports.show', [
            'report' => $report,
            'filters' => $filters,
            'result' => $report->run($filters),
            'currencies' => ReportFilters::currencies(),
            'groups' => $this->reports->grouped(),
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $report = $this->report($report);
        $filters = ReportFilters::fromRequest($request, $report);
        $result = $report->run($filters);
        Activity::record("Exported the {$report->title()} report to CSV", null, ['filters' => $filters->query()]);

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            $multiple = count($result->tables) > 1;

            foreach ($result->tables as $i => $table) {
                if ($i > 0) {
                    fputcsv($out, [], escape: '');
                }
                if ($multiple && $table->title !== null) {
                    fputcsv($out, [Cell::csv($table->title, 'text')], escape: '');
                }

                fputcsv($out, array_map(fn (array $column) => $column[0], array_values($table->columns)), escape: '');
                $rows = $table->totals === null ? $table->rows : [...$table->rows, $table->totals];
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn (string $key) => Cell::csv($row[$key] ?? null, $table->type($key)), array_keys($table->columns)), escape: '');
                }
            }

            fclose($out);
        }, "{$report->key()}-{$filters->from->toDateString()}-to-{$filters->to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    private function report(string $key): Report
    {
        return $this->reports->find($key) ?? abort(404);
    }
}
