<?php

namespace App\Reports\Support;

use App\Enums\TicketStatus;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;

/** How many tickets opened in the dates have each tag. */
class TicketTags extends Report
{
    public function key(): string
    {
        return 'ticket-tags';
    }

    public function title(): string
    {
        return 'Ticket tags';
    }

    public function group(): string
    {
        return ReportRegistry::SUPPORT;
    }

    public function description(): string
    {
        return 'How many tickets opened in these dates have each tag, and how many of them are still open.';
    }

    public function filters(): array
    {
        return [self::DATES];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $rows = DB::table('ticket_tags')
            ->join('ticket_ticket_tag', 'ticket_ticket_tag.ticket_tag_id', '=', 'ticket_tags.id')
            ->join('tickets', 'tickets.id', '=', 'ticket_ticket_tag.ticket_id')
            ->whereNull('tickets.merged_into_id')
            ->whereBetween('tickets.created_at', $filters->between())
            ->groupBy('ticket_tags.id', 'ticket_tags.name')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw('ticket_tags.id, ticket_tags.name, COUNT(*) as tickets, SUM(CASE WHEN tickets.status != ? THEN 1 ELSE 0 END) as still_open', [TicketStatus::Closed->value])
            ->get();

        $table = new ReportTable(['tag' => ['Tag', ReportTable::TEXT], 'tickets' => ['Tickets', ReportTable::NUMBER], 'still_open' => ['Still open', ReportTable::NUMBER]]);
        $table->chart = 'tickets';
        $table->empty = 'No tagged tickets were opened in these dates.';
        foreach ($rows as $row) {
            $table->add(['tag' => $row->name, 'tickets' => (int) $row->tickets, 'still_open' => (int) $row->still_open, '_links' => ['tag' => route('admin.tickets.index', ['tag' => $row->name, 'status' => 'all'])]]);
        }

        $tagged = DB::table('ticket_ticket_tag')->join('tickets', 'tickets.id', '=', 'ticket_ticket_tag.ticket_id')
            ->whereNull('tickets.merged_into_id')->whereBetween('tickets.created_at', $filters->between())->distinct()->count('ticket_ticket_tag.ticket_id');
        $opened = DB::table('tickets')->whereNull('merged_into_id')->whereBetween('created_at', $filters->between())->count();

        return (new ReportResult)->table($table)
            ->note("{$tagged} of the {$opened} tickets opened in these dates have a tag. A ticket with two tags counts under both.");
    }
}
