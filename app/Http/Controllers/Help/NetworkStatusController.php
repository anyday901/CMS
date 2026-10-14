<?php

namespace App\Http\Controllers\Help;

use App\Http\Controllers\Controller;
use App\Models\NetworkIssue;
use App\Models\Server;
use Illuminate\View\View;

/** Current problems, planned work, recently resolved issues and the status of the servers staff chose to show. */
class NetworkStatusController extends Controller
{
    public function __invoke(): View
    {
        return view('help.network.index', [
            'current' => NetworkIssue::current()->with('server')->get(),
            'upcoming' => NetworkIssue::upcoming()->with('server')->get(),
            'resolved' => NetworkIssue::resolved()->with('server')->where('updated_at', '>=', now()->subDays(30))->limit(20)->get(),
            'servers' => Server::where('show_status', true)->where('monitor', true)->orderBy('name')->get(),
        ]);
    }
}
