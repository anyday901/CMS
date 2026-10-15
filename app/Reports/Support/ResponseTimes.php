<?php

namespace App\Reports\Support;

use App\Reports\Periods;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use App\Reports\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** How long tickets waited for the first staff reply, and how long they took to close. */
class ResponseTimes extends Report
{
    public function key(): string
    {
        return 'response-times';
    }

    public function title(): string
    {
        return 'Response and resolution times';
    }

    public function group(): string
    {
        return ReportRegistry::SUPPORT;
    }

    public function description(): string
    {
        return 'How long tickets opened in these dates waited for the first staff reply, and how long they took to close, by department.';
    }

    public function filters(): array
    {
        return [self::DATES];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $tickets = DB::table('tickets')->whereNull('merged_into_id')->whereBetween('created_at', $filters->between())
            ->get(['id', 'department_id', 'created_at', 'first_response_at', 'closed_at']);

        // Tickets from before first replies were recorded: take it from the messages.
        $missing = $tickets->whereNull('first_response_at')->pluck('id');
        $firstReplies = $missing->isEmpty() ? collect() : Sql::by(DB::table('ticket_messages')
            ->whereIn('ticket_id', $missing)->whereNull('client_id')->where('guest', false)->where('internal', false), 'ticket_id', 'MIN(created_at)');

        $departments = DB::table('departments')->orderBy('name')->pluck('name', 'id');
        $groups = ['all' => ['response' => [], 'resolution' => [], 'tickets' => 0]];
        foreach ($tickets as $ticket) {
            $opened = CarbonImmutable::parse($ticket->created_at);
            $first = $ticket->first_response_at ?? $firstReplies[$ticket->id] ?? null;
            $response = Periods::minutes($opened, $first ? CarbonImmutable::parse($first) : null);
            $resolution = Periods::minutes($opened, $ticket->closed_at ? CarbonImmutable::parse($ticket->closed_at) : null);

            foreach (['all', $ticket->department_id] as $key) {
                $groups[$key] ??= ['response' => [], 'resolution' => [], 'tickets' => 0];
                $groups[$key]['tickets']++;
                $response === null || $groups[$key]['response'][] = $response;
                $resolution === null || $groups[$key]['resolution'][] = $resolution;
            }
        }

        $table = new ReportTable([
            'department' => ['Department', ReportTable::TEXT],
            'tickets' => ['Tickets', ReportTable::NUMBER],
            'answered' => ['Answered', ReportTable::NUMBER],
            'response_avg' => ['Average first reply', ReportTable::DURATION],
            'response_median' => ['Median first reply', ReportTable::DURATION],
            'closed' => ['Closed', ReportTable::NUMBER],
            'resolution_avg' => ['Average time to close', ReportTable::DURATION],
            'resolution_median' => ['Median time to close', ReportTable::DURATION],
        ]);
        $table->empty = 'No tickets were opened in these dates.';

        foreach ($departments as $id => $name) {
            if (isset($groups[$id])) {
                $table->add(['department' => $name] + $this->times($groups[$id]));
            }
        }
        if ($tickets->isNotEmpty()) {
            $table->totals = ['department' => 'All departments'] + $this->times($groups['all']);
        }

        return (new ReportResult)->table($table)
            ->note('For tickets opened in these dates, in calendar time (nights and weekends count). First reply is the first staff reply the client could see; internal notes don\'t count. Median is the middle ticket, which a few very slow tickets don\'t pull up.');
    }

    /**
     * @param  array{response: list<int>, resolution: list<int>, tickets: int}  $group
     * @return array<string, int|null>
     */
    private function times(array $group): array
    {
        $average = fn (array $values) => $values ? (int) round(array_sum($values) / count($values)) : null;
        $median = fn (array $values) => ($m = Periods::median($values)) === null ? null : (int) round($m);

        return [
            'tickets' => $group['tickets'],
            'answered' => count($group['response']),
            'response_avg' => $average($group['response']),
            'response_median' => $median($group['response']),
            'closed' => count($group['resolution']),
            'resolution_avg' => $average($group['resolution']),
            'resolution_median' => $median($group['resolution']),
        ];
    }
}
