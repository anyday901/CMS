<?php

namespace App\Reports\Support;

use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;

/** Replies and internal notes each staff member wrote, with the ratings on tickets they answered. */
class StaffReplies extends Report
{
    public function key(): string
    {
        return 'replies-by-staff';
    }

    public function title(): string
    {
        return 'Replies by staff';
    }

    public function group(): string
    {
        return ReportRegistry::SUPPORT;
    }

    public function description(): string
    {
        return 'Replies and internal notes each staff member wrote in these dates, how many tickets they answered, and those tickets\' ratings.';
    }

    public function filters(): array
    {
        return [self::DATES];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $messages = DB::table('ticket_messages')
            ->leftJoin('users', 'users.id', '=', 'ticket_messages.user_id')
            ->leftJoin('ticket_feedback', 'ticket_feedback.ticket_id', '=', 'ticket_messages.ticket_id')
            ->whereNull('ticket_messages.client_id')
            ->where('ticket_messages.guest', false)
            ->whereBetween('ticket_messages.created_at', $filters->between())
            ->get(['ticket_messages.ticket_id', 'ticket_messages.user_id', 'ticket_messages.author_name', 'ticket_messages.internal', 'users.name', 'ticket_feedback.rating']);

        $staff = [];
        foreach ($messages as $message) {
            $key = $message->user_id ? "user:{$message->user_id}" : 'name:'.strtolower((string) $message->author_name);
            $staff[$key] ??= ['staff' => $message->name ?? $message->author_name ?? 'Former staff', 'replies' => 0, 'notes' => 0, 'tickets' => [], 'ratings' => []];
            if ($message->internal) {
                $staff[$key]['notes']++;

                continue;
            }
            $staff[$key]['replies']++;
            $staff[$key]['tickets'][$message->ticket_id] = true;
            if ($message->rating !== null) {
                $staff[$key]['ratings'][$message->ticket_id] = (int) $message->rating;
            }
        }

        $table = new ReportTable([
            'staff' => ['Staff', ReportTable::TEXT],
            'replies' => ['Replies', ReportTable::NUMBER],
            'tickets' => ['Tickets answered', ReportTable::NUMBER],
            'notes' => ['Internal notes', ReportTable::NUMBER],
            'rated' => ['Rated tickets', ReportTable::NUMBER],
            'rating' => ['Average rating', ReportTable::NUMBER],
        ]);
        $table->chart = 'replies';
        $table->empty = 'No staff replies in these dates.';

        uasort($staff, fn (array $a, array $b) => $b['replies'] <=> $a['replies']);
        foreach ($staff as $row) {
            $table->add([
                'staff' => $row['staff'],
                'replies' => $row['replies'],
                'tickets' => count($row['tickets']),
                'notes' => $row['notes'],
                'rated' => count($row['ratings']),
                'rating' => $row['ratings'] ? round(array_sum($row['ratings']) / count($row['ratings']), 1) : null,
            ]);
        }

        return (new ReportResult)->table($table->withTotals(['staff' => 'Total', 'tickets' => null, 'rated' => null, 'rating' => null]))
            ->note('Average rating is out of 5, from the feedback on the tickets each person answered in these dates. Replies imported from WHMCS by an admin with no staff account here are listed under their name.');
    }
}
