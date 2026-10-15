<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\TicketFeedback;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What clients and guests rated the support on their closed tickets: overall, per staff member, and each rating with its comment. */
class TicketFeedbackController extends Controller
{
    /** Periods to look back over, in days; null is all time. */
    private const PERIODS = ['30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months', 'all' => 'All time'];

    public function __invoke(Request $request): View
    {
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : '90';
        $department = $request->integer('department') ?: null;

        $scope = fn (): Builder => TicketFeedback::query()
            ->when($period !== 'all', fn ($query) => $query->where('ticket_feedback.created_at', '>=', now()->subDays((int) $period)))
            ->when($department, fn ($query, $id) => $query->whereHas('ticket', fn ($ticket) => $ticket->where('department_id', $id)));

        $ratings = $scope()->pluck('rating');

        return view('admin.tickets.feedback', [
            'periods' => self::PERIODS,
            'period' => $period,
            'department' => $department,
            'departments' => Department::orderBy('name')->get(),
            'count' => $ratings->count(),
            'average' => $ratings->avg(),
            'distribution' => collect(range(TicketFeedback::MAX_RATING, 1))->mapWithKeys(fn (int $rating) => [$rating => $ratings->filter(fn ($value) => $value === $rating)->count()]),
            'staff' => $this->byStaff($scope()),
            'feedback' => $scope()->with(['ticket.department', 'ticket.client'])->latest()->latest('id')->paginate(25)->withQueryString(),
        ]);
    }

    /**
     * Average rating of each staff member who replied on the rated tickets.
     * A ticket counts once for everyone who replied on it.
     *
     * @param  Builder<TicketFeedback>  $feedback
     * @return list<array{name: string, count: int, average: float}>
     */
    private function byStaff(Builder $feedback): array
    {
        $ratings = $feedback->pluck('rating', 'ticket_id');

        return TicketMessage::query()
            ->whereIn('ticket_id', $ratings->keys())
            ->whereNotNull('user_id')
            ->where('internal', false)
            ->with('user')
            ->get(['ticket_id', 'user_id'])
            ->unique(fn (TicketMessage $message) => "{$message->ticket_id}:{$message->user_id}")
            ->groupBy('user_id')
            ->map(fn ($messages) => [
                'name' => $messages->first()->user->name ?? 'Former staff',
                'count' => $messages->count(),
                'average' => round($messages->avg(fn (TicketMessage $message) => $ratings[$message->ticket_id]), 2),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
