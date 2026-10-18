<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GatewayLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Every callback and webhook the payment gateways sent, with what became of each. */
class GatewayLogController extends Controller
{
    public function index(Request $request): View
    {
        $gateways = GatewayLog::GATEWAYS + GatewayLog::query()->distinct()->orderBy('gateway')->pluck('gateway', 'gateway')
            ->map(fn (string $key) => (new GatewayLog(['gateway' => $key]))->gatewayLabel())->all();
        $gateway = array_key_exists((string) $request->query('gateway'), $gateways) ? (string) $request->query('gateway') : null;
        $outcome = array_key_exists((string) $request->query('outcome'), GatewayLog::OUTCOMES) ? (string) $request->query('outcome') : null;
        $search = trim((string) $request->query('q'));

        return view('admin.gateway-log.index', [
            'gateways' => $gateways,
            'gateway' => $gateway,
            'outcome' => $outcome,
            'search' => $search,
            'entries' => GatewayLog::query()
                ->with('invoice')
                ->when($gateway, fn ($query, $value) => $query->where('gateway', $value))
                ->when($outcome, fn ($query, $value) => $query->where('outcome', $value))
                ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                    ->where('event', 'like', '%'.addcslashes($search, '\\%_').'%')
                    ->orWhere('message', 'like', '%'.addcslashes($search, '\\%_').'%')
                    ->orWhere('payload', 'like', '%'.addcslashes($search, '\\%_').'%')))
                ->latest()->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function show(GatewayLog $entry): View
    {
        $entry->load('client', 'invoice');

        return view('admin.gateway-log.show', compact('entry'));
    }
}
