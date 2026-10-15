<?php

namespace App\Reports\Support;

use App\Models\TicketFeedback;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportTable;
use Illuminate\Support\Facades\DB;

/** The ratings clients gave closed tickets, and what they wrote. */
class Feedback extends Report
{
    public function key(): string
    {
        return 'ticket-feedback';
    }

    public function title(): string
    {
        return 'Ratings and feedback';
    }

    public function group(): string
    {
        return ReportRegistry::SUPPORT;
    }

    public function description(): string
    {
        return 'How clients rated their closed tickets in these dates, and every comment they left.';
    }

    public function filters(): array
    {
        return [self::DATES];
    }

    public function run(ReportFilters $filters): ReportResult
    {
        $feedback = DB::table('ticket_feedback')
            ->join('tickets', 'tickets.id', '=', 'ticket_feedback.ticket_id')
            ->leftJoin('clients', 'clients.id', '=', 'tickets.client_id')
            ->whereBetween('ticket_feedback.created_at', $filters->between())
            ->orderByDesc('ticket_feedback.created_at')
            ->get(['ticket_feedback.rating', 'ticket_feedback.comment', 'ticket_feedback.created_at', 'tickets.id', 'tickets.subject', 'tickets.guest_name', 'clients.first_name', 'clients.last_name']);

        $ratings = new ReportTable([
            'rating' => ['Rating', ReportTable::TEXT],
            'tickets' => ['Tickets', ReportTable::NUMBER],
            'share' => ['Share', ReportTable::PERCENT],
        ], 'Ratings');
        $ratings->chart = 'tickets';
        foreach (range(TicketFeedback::MAX_RATING, 1) as $rating) {
            $count = $feedback->where('rating', $rating)->count();
            $ratings->add(['rating' => str_repeat('★', $rating), 'tickets' => $count, 'share' => $feedback->isEmpty() ? null : round($count / $feedback->count() * 100, 1)]);
        }
        $ratings->withTotals(['rating' => $feedback->isEmpty() ? 'Total' : 'Average '.number_format($feedback->avg('rating'), 2).' of '.TicketFeedback::MAX_RATING]);

        $comments = new ReportTable([
            'date' => ['Rated', ReportTable::DATE],
            'ticket' => ['Ticket', ReportTable::TEXT],
            'from' => ['From', ReportTable::TEXT],
            'rating' => ['Rating', ReportTable::NUMBER],
            'comment' => ['Comment', ReportTable::TEXT],
        ], 'Comments');
        $comments->empty = 'No comments in these dates.';
        foreach ($feedback->whereNotNull('comment') as $item) {
            $comments->add([
                'date' => substr((string) $item->created_at, 0, 10),
                'ticket' => "#{$item->id} {$item->subject}",
                'from' => $item->first_name !== null ? trim("{$item->first_name} {$item->last_name}") : ($item->guest_name ?? 'Guest'),
                'rating' => (int) $item->rating,
                'comment' => $item->comment,
                '_links' => ['ticket' => route('admin.tickets.show', $item->id)],
            ]);
        }

        return (new ReportResult)->table($ratings)->table($comments)
            ->note('Ratings are out of 5, given when a ticket closes in departments that ask for feedback.');
    }
}
