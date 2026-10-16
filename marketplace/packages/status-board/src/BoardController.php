<?php

namespace CmsAddons\StatusBoard;

use App\Addons\AddonManager;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class BoardController extends Controller
{
    public function __invoke(AddonManager $addons): View
    {
        $today = today();

        return view('status-board::board', [
            'title' => $addons->find('status-board')?->setting('title') ?: 'Today',
            'received' => Money::format((int) Transaction::where('created_at', '>=', $today)->sum('amount'), config('billing.currency')),
            'orders' => Order::where('created_at', '>=', $today)->count(),
            'pending' => Order::where('status', OrderStatus::Pending)->count(),
            'tickets' => Ticket::awaitingStaff()->count(),
        ]);
    }
}
