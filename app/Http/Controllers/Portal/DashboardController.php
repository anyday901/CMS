<?php

namespace App\Http\Controllers\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $client = $request->user('client');

        return view('portal.dashboard', [
            'client' => $client,
            'unpaid' => $client->invoices()->where('status', InvoiceStatus::Unpaid)->orderBy('due_date')->get(),
            'services' => $client->services()->with('product')
                ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])
                ->orderBy('next_due_date')->get(),
        ]);
    }
}
