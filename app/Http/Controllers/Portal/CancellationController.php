<?php

namespace App\Http\Controllers\Portal;

use App\Billing\ServiceCanceller;
use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Clients ask to cancel their own services, and can take an end-of-period request back. */
class CancellationController extends Controller
{
    public function __construct(private ServiceCanceller $canceller) {}

    public function store(Request $request, int $service): RedirectResponse
    {
        $service = $this->service($request, $service);
        $data = $request->validate(CancellationRequest::rules());

        try {
            $cancellation = $this->canceller->request($service, $data['type'], $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cancellation' => $e->getMessage()]);
        }

        return redirect()->route('portal.services.show', $service)->with('status', $cancellation->status === CancellationRequest::COMPLETED
            ? 'Your service has been cancelled.'
            : "Your service will be cancelled on {$cancellation->cancel_on->format('M j, Y')}.");
    }

    public function destroy(Request $request, int $service): RedirectResponse
    {
        $service = $this->service($request, $service);
        $pending = $service->pendingCancellation ?? abort(404);

        try {
            $this->canceller->withdraw($pending);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cancellation' => $e->getMessage()]);
        }

        return redirect()->route('portal.services.show', $service)->with('status', 'Your cancellation request has been withdrawn.');
    }

    private function service(Request $request, int $id): Service
    {
        return $request->user('client')->services()->with('product')->findOrFail($id);
    }
}
