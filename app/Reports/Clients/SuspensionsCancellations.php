<?php

namespace App\Reports\Clients;

use App\Enums\ServiceStatus;
use App\Models\CancellationRequest;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Why services are suspended now, and why clients asked to cancel in the dates. */
class SuspensionsCancellations extends Report
{
    public function key(): string
    {
        return 'suspensions-and-cancellations';
    }

    public function title(): string
    {
        return 'Suspensions and cancellations';
    }

    public function group(): string
    {
        return ReportRegistry::CLIENTS;
    }

    public function description(): string
    {
        return 'Services suspended now by the reason given, and cancellation requests made in these dates by reason.';
    }

    public function filters(): array
    {
        return [self::DATES];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $suspended = new ReportTable(['reason' => ['Reason', ReportTable::TEXT], 'services' => ['Services', ReportTable::NUMBER]], 'Suspended now');
        $suspended->chart = 'services';
        $suspended->empty = 'No services are suspended.';
        foreach ($this->byReason(DB::table('services')->where('status', ServiceStatus::Suspended->value)->pluck('suspension_reason')) as $reason => $count) {
            $suspended->add(['reason' => $reason, 'services' => $count]);
        }

        $requests = DB::table('cancellation_requests')->whereBetween('created_at', $filters->between())->get(['reason', 'status', 'type']);
        $cancelled = new ReportTable([
            'reason' => ['Reason', ReportTable::TEXT],
            'requests' => ['Requests', ReportTable::NUMBER],
            'completed' => ['Cancelled', ReportTable::NUMBER],
            'pending' => ['Waiting', ReportTable::NUMBER],
            'withdrawn' => ['Withdrawn', ReportTable::NUMBER],
        ], 'Cancellation requests in these dates');
        $cancelled->chart = 'requests';
        $cancelled->empty = 'No cancellation requests in these dates.';

        $grouped = $requests->groupBy(fn ($request) => $this->reason($request->reason));
        foreach ($grouped->sortByDesc(fn ($group) => $group->count()) as $reason => $group) {
            $cancelled->add([
                'reason' => $reason,
                'requests' => $group->count(),
                'completed' => $group->where('status', CancellationRequest::COMPLETED)->count(),
                'pending' => $group->where('status', CancellationRequest::PENDING)->count(),
                'withdrawn' => $group->where('status', CancellationRequest::WITHDRAWN)->count(),
            ]);
        }

        $types = new ReportTable(['type' => ['When', ReportTable::TEXT], 'requests' => ['Requests', ReportTable::NUMBER]], 'Cancellation requests by when');
        foreach ([CancellationRequest::IMMEDIATE => 'Straight away', CancellationRequest::END_OF_PERIOD => 'End of billing period'] as $type => $label) {
            $types->add(['type' => $label, 'requests' => $requests->where('type', $type)->count()]);
        }

        return (new ReportResult)
            ->table($suspended->withTotals(['reason' => 'Total']))
            ->table($cancelled->withTotals(['reason' => 'Total']))
            ->table($types->withTotals(['type' => 'Total']))
            ->note('Reasons are grouped when they match, ignoring capitals and spacing.');
    }

    /**
     * @param  iterable<string|null>  $reasons
     * @return array<string, int>
     */
    private function byReason(iterable $reasons): array
    {
        $counts = [];
        foreach ($reasons as $reason) {
            $key = $this->reason($reason);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /** The reason as it is grouped and shown: one line, at most 120 characters. */
    private function reason(?string $reason): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $reason));

        return $clean === '' ? 'No reason given' : Str::ucfirst(Str::lower(Str::limit($clean, 120)));
    }
}
