<?php

namespace App\Http\Controllers\Admin;

use App\Billing\CreditApplier;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\Client;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Todo;
use App\Models\Transaction;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $unpaid = Invoice::where('status', InvoiceStatus::Unpaid);

        return view('admin.dashboard', [
            'activeClients' => Client::where('status', ClientStatus::Active)->count(),
            'activeServices' => Service::where('status', ServiceStatus::Active)->count(),
            'pendingServices' => Service::where('status', ServiceStatus::Pending)->count(),
            'incomeThisMonth' => (int) Transaction::where('created_at', '>=', now()->startOfMonth())
                // Paying from credit moves money already received, so it isn't new income.
                ->where('gateway', '!=', CreditApplier::GATEWAY)->sum('amount'),
            'pendingOrders' => Order::where('status', OrderStatus::Pending)->with(['client', 'invoice', 'services.product'])->oldest('id')->limit(10)->get(),
            'cancellations' => CancellationRequest::pending()->with(['service.client', 'service.product'])->orderBy('cancel_on')->limit(10)->get(),
            'todos' => Todo::unfinished()->where(fn ($query) => $query->where('user_id', request()->user()->id)->orWhereNull('user_id'))
                ->with(['user', 'client'])->orderByRaw('due_on is null')->orderBy('due_on')->oldest('id')->limit(10)->get(),
            'openTasks' => FulfillmentTask::open()->with(['service.client', 'service.product'])->oldest()->limit(10)->get(),
            'waitingTickets' => request()->user()->can('manage-tickets')
                ? Ticket::awaitingStaff()->with(['client', 'department', 'assignee', 'tags'])->orderByRaw(TicketPriority::orderSql())->oldest('last_reply_at')->limit(10)->get()
                : collect(),
            'serverProblems' => request()->user()->can('manage-settings')
                ? Server::where('monitor', true)->whereIn('status', [Server::DOWN, Server::WARNING])->orderBy('name')->get()
                : collect(),
            'unpaidCount' => (clone $unpaid)->count(),
            'unpaidTotal' => (int) (clone $unpaid)->sum('total'),
            'overdue' => (clone $unpaid)->whereDate('due_date', '<', today())
                ->with('client')->orderBy('due_date')->limit(10)->get(),
        ]);
    }
}
