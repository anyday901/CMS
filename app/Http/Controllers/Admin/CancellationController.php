<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ServiceCanceller;
use App\Http\Controllers\Controller;
use App\Models\CancellationRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Staff request a cancellation for a client, or withdraw one. */
class CancellationController extends Controller
{
    public function __construct(private ServiceCanceller $canceller) {}

    public function store(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate(CancellationRequest::rules());

        try {
            $cancellation = $this->canceller->request($service, $data['type'], $data['reason'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cancellation' => $e->getMessage()]);
        }

        return back()->with('status', $cancellation->status === CancellationRequest::COMPLETED
            ? 'Service cancelled.'
            : "Service set to be cancelled on {$cancellation->cancel_on->format('M j, Y')}.");
    }

    public function destroy(CancellationRequest $cancellation): RedirectResponse
    {
        try {
            $this->canceller->withdraw($cancellation);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cancellation' => $e->getMessage()]);
        }

        return back()->with('status', 'Cancellation request withdrawn.');
    }
}
