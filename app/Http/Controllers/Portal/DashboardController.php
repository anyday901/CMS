<?php

namespace App\Http\Controllers\Portal;

use App\Auth\PortalContact;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\ClientContact;
use App\Models\NetworkIssue;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $client = $request->user('client');

        return view('portal.dashboard', [
            'client' => $client,
            'contact' => PortalContact::current(),
            'can' => collect(array_keys(ClientContact::PERMISSIONS))->mapWithKeys(fn (string $permission) => [$permission => PortalContact::allows($permission)])->all(),
            'unpaid' => $client->invoices()->where('status', InvoiceStatus::Unpaid)->withoutFunds()->orderBy('due_date')->get(),
            'services' => $client->services()->with('product')
                ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])
                ->orderBy('next_due_date')->get(),
            'credit' => $client->creditEntries()->latest('id')->limit(10)->get(),
            'announcements' => Announcement::visible()->translated()->limit(3)->get(),
            // Problems on the servers the client's services are on, and ones not tied to a server.
            'issues' => NetworkIssue::current()->with('server')
                ->where(fn ($query) => $query->whereNull('server_id')->orWhereIn('server_id', $client->services()->whereNotNull('server_id')
                    ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])->select('server_id')))
                ->get(),
        ]);
    }
}
