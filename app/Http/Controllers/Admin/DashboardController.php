<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
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
            'incomeThisMonth' => (int) Transaction::where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'unpaidCount' => (clone $unpaid)->count(),
            'unpaidTotal' => (int) (clone $unpaid)->sum('total'),
            'overdue' => (clone $unpaid)->whereDate('due_date', '<', today())
                ->with('client')->orderBy('due_date')->limit(10)->get(),
        ]);
    }
}
