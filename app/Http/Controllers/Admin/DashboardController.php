<?php

namespace App\Http\Controllers\Admin;

use App\Billing\DisputeRecorder;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\Currency;
use App\Models\EmailLog;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\LoginAttempt;
use App\Models\Order;
use App\Models\ScheduledTaskRun;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SystemOverview;
use App\System\UpdateChecker;
use App\System\Version;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The admin home page, laid out like the WHMCS dashboard: shortcuts and system
 * details down the side, four counts across the top, the orders and income
 * chart with billing and client activity under it, and automation and the
 * activity log on the right. The work queues follow underneath.
 */
class DashboardController extends Controller
{
    /** Staff count as online when their session was used this recently. */
    public const ONLINE_MINUTES = 15;

    public function __invoke(Request $request, UpdateChecker $updates): View
    {
        $user = $request->user();
        $unpaid = Invoice::where('status', InvoiceStatus::Unpaid);
        $overview = SystemOverview::fromRequest($request->query('period'));
        $release = $updates->cached();

        return view('admin.dashboard', [
            'pendingOrderCount' => Order::where('status', OrderStatus::Pending)->count(),
            'waitingTicketCount' => Ticket::awaitingStaff()->count(),
            'pendingCancellationCount' => CancellationRequest::pending()->count(),
            'openTaskCount' => FulfillmentTask::open()->count(),
            'overview' => $overview,
            'activeClients' => Client::where('status', ClientStatus::Active)->count(),
            'activeServices' => Service::where('status', ServiceStatus::Active)->count(),
            'pendingServices' => Service::where('status', ServiceStatus::Pending)->count(),
            'incomeToday' => $this->income(today()),
            'incomeThisMonth' => $this->income(now()->startOfMonth()),
            'incomeThisYear' => $this->income(now()->startOfYear()),
            'incomeAllTime' => $this->income(null),
            'paymentsToReview' => $this->paymentsToReview()->with(['client', 'invoice'])->latest('id')->limit(5)->get(),
            'paymentsToReviewCount' => $this->paymentsToReview()->count(),
            'automation' => $this->automation(),
            'lastAutomationRun' => ScheduledTaskRun::where('task', 'billing:run')->where('status', '!=', ScheduledTaskRun::SKIPPED)->latest('started_at')->first(),
            'activity' => $user->can('manage-settings') ? Activity::latest('id')->limit(8)->get() : null,
            'clientsSignedIn' => LoginAttempt::where('area', LoginAttempt::PORTAL)->where('outcome', LoginAttempt::SUCCESS)
                ->where('created_at', '>=', now()->subHour())->distinct()->count('email'),
            'recentClientLogins' => LoginAttempt::where('area', LoginAttempt::PORTAL)->where('outcome', LoginAttempt::SUCCESS)
                ->with(['account', 'client'])->latest('id')->limit(5)->get(),
            'staffOnline' => $this->staffOnline($user),
            'version' => Version::current(),
            'newerRelease' => $release !== null && Version::newer($release['version']) ? $release['version'] : null,
            'pendingOrders' => Order::where('status', OrderStatus::Pending)->with(['client', 'invoice', 'services.product'])->oldest('id')->limit(10)->get(),
            'cancellations' => CancellationRequest::pending()->with(['service.client', 'service.product'])->orderBy('cancel_on')->limit(10)->get(),
            'todos' => Todo::unfinished()->where(fn ($query) => $query->where('user_id', $user->id)->orWhereNull('user_id'))
                ->with(['user', 'client'])->orderByRaw('due_on is null')->orderBy('due_on')->oldest('id')->limit(10)->get(),
            'openTasks' => FulfillmentTask::open()->with(['service.client', 'service.product'])->oldest()->limit(10)->get(),
            'waitingTickets' => $user->can('manage-tickets')
                ? Ticket::awaitingStaff()->with(['client', 'department', 'assignee', 'tags'])->orderByRaw(TicketPriority::orderSql())->oldest('last_reply_at')->limit(10)->get()
                : collect(),
            'serverProblems' => $user->can('manage-settings')
                ? Server::where('monitor', true)->whereIn('status', [Server::DOWN, Server::WARNING])->orderBy('name')->get()
                : collect(),
            'unpaidCount' => (clone $unpaid)->count(),
            'unpaidTotal' => Currency::sumInDefault((clone $unpaid)->groupBy('currency')->selectRaw('currency, sum(total) as total')->pluck('total', 'currency')),
            'overdue' => (clone $unpaid)->whereDate('due_date', '<', today())
                ->with('client')->orderBy('due_date')->limit(10)->get(),
        ]);
    }

    /** Money received since a time, or ever, in minor units of the default currency. */
    private function income($since): int
    {
        return Currency::sumInDefault(SystemOverview::incomeQuery()->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->groupBy('currency')->selectRaw('currency, sum(amount) as amount')->pluck('amount', 'currency'));
    }

    /** Payments still clearing at the gateway, and payments with an open dispute. */
    private function paymentsToReview(): Builder
    {
        return Transaction::where(fn ($query) => $query->where('pending', true)
            ->orWhere(fn ($query) => $query->whereNotNull('dispute_status')
                ->whereNotIn('dispute_status', [...DisputeRecorder::LOST_STATES, 'won'])));
    }

    /**
     * What the automatic tasks did today, with a count for each of the last
     * seven days for the sparkline.
     *
     * @return list<array{label: string, today: int, days: list<int>, color: string}>
     */
    private function automation(): array
    {
        $since = CarbonImmutable::today()->subDays(6);
        $system = fn (string $pattern) => Activity::where('actor_type', 'system')->where('description', 'like', $pattern)
            ->where('created_at', '>=', $since)->pluck('created_at');

        $metrics = [
            ['Invoices Created', $system('Generated invoice #%'), 'text-brand-600'],
            ['Overdue Terminations', $system('Terminated %'), 'text-red-600'],
            ['Overdue Suspensions', $system('Suspended %'), 'text-amber-600'],
            ['Inactive Tickets Closed', $system('Closed inactive ticket #%'), 'text-pink-600'],
            ['Overdue Reminders', EmailLog::where('template', 'invoice_overdue')->where('created_at', '>=', $since)->pluck('created_at'), 'text-ink-600'],
            ['Cancellations Processed', CancellationRequest::where('completed_at', '>=', $since)->pluck('completed_at'), 'text-purple-600'],
        ];

        return array_map(function (array $metric) use ($since) {
            [$label, $times, $color] = $metric;
            $days = array_fill(0, 7, 0);
            foreach ($times as $time) {
                $days[min(6, max(0, (int) $since->diffInDays(CarbonImmutable::parse($time)->startOfDay())))]++;
            }

            return ['label' => $label, 'today' => $days[6], 'days' => $days, 'color' => $color];
        }, $metrics);
    }

    /**
     * Staff whose session was used in the last few minutes. Only database
     * sessions record that; with another session driver it is just you.
     *
     * @return Collection<int, User>
     */
    private function staffOnline(User $user): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect([$user]);
        }

        $ids = DB::table(config('session.table', 'sessions'))->whereNotNull('user_id')
            ->where('last_activity', '>=', now()->subMinutes(self::ONLINE_MINUTES)->getTimestamp())
            ->pluck('user_id');

        return User::whereIn('id', $ids->push($user->id)->unique())->orderBy('name')->get();
    }
}
