<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Activity;
use App\Models\CancellationRequest;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancellation requests: straight away, or when the period the client has
 * paid for ends. A request stops the service being invoiced again, and its
 * unpaid charges come off open invoices. The service and its add-ons are
 * cancelled on the day, by the nightly billing run for end-of-period ones.
 */
class ServiceCanceller
{
    public function __construct(private ServiceLifecycle $lifecycle, private InvoiceCanceller $invoices) {}

    /** Why cancellation can't be requested for this service, or null when it can. */
    public function problem(Service $service): ?string
    {
        return match (true) {
            ! in_array($service->status, ServiceStatus::billable(), true) => 'Only active or suspended services can be cancelled.',
            $service->pendingCancellation()->exists() => 'Cancellation has already been requested for this service.',
            default => null,
        };
    }

    /**
     * Records the request and carries it out at once when it's due today.
     *
     * @param  ?User  $staff  The staff member asking on the client's behalf.
     *
     * @throws InvalidArgumentException when the service can't be cancelled this way.
     */
    public function request(Service $service, string $type, ?string $reason = null, ?User $staff = null): CancellationRequest
    {
        $request = DB::transaction(function () use ($service, $type, $reason, $staff) {
            $service = Service::lockForUpdate()->with('product')->findOrFail($service->id);

            if ($problem = $this->problem($service)) {
                throw new InvalidArgumentException($problem);
            }

            $request = $service->cancellationRequests()->create([
                'type' => $type,
                'reason' => $reason,
                'cancel_on' => $this->cancelOn($service, $type),
                'requested_by' => $staff?->id,
            ]);
            Activity::record(
                "Requested cancellation of {$service->description()} ".($type === CancellationRequest::IMMEDIATE ? 'straight away' : "on {$request->cancel_on->format('M j, Y')}"),
                $service,
                array_filter(['reason' => $reason]),
            );
            $this->dropUnpaidCharges($service);

            return $request;
        });

        if ($request->cancel_on->lte(today())) {
            $this->carryOut($request);
        }

        return $request->refresh();
    }

    /** Cancels the service of each request due by $today. */
    public function run(CarbonInterface $today): int
    {
        $due = CancellationRequest::pending()->whereDate('cancel_on', '<=', $today)->orderBy('id')->get();

        return $due->filter(fn (CancellationRequest $request) => $this->carryOut($request))->count();
    }

    /** Takes back a request that hasn't been carried out. The service is invoiced again as usual. */
    public function withdraw(CancellationRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request = CancellationRequest::lockForUpdate()->with('service.product')->findOrFail($request->id);

            if ($request->status !== CancellationRequest::PENDING) {
                throw new InvalidArgumentException('This cancellation request has already been '.$request->status.'.');
            }

            $request->update(['status' => CancellationRequest::WITHDRAWN]);
            Activity::record("Withdrew the cancellation request for {$request->service->description()}", $request->service);
        });
    }

    /** @return bool Whether this call cancelled the service. */
    private function carryOut(CancellationRequest $request): bool
    {
        return DB::transaction(function () use ($request) {
            $request = CancellationRequest::lockForUpdate()->findOrFail($request->id);

            if ($request->status !== CancellationRequest::PENDING) {
                return false;
            }

            $service = Service::lockForUpdate()->with(['product', 'client'])->findOrFail($request->service_id);
            // Staff or the overdue run may have ended it in the meantime.
            $live = in_array($service->status, ServiceStatus::billable(), true);

            if ($live) {
                $this->lifecycle->cancel($service);
            }

            // Its add-ons went with it, so their own requests are done too.
            CancellationRequest::pending()
                ->where(fn ($query) => $query->whereKey($request->id)->orWhereIn('service_id', $service->addons()->select('id')))
                ->update(['status' => CancellationRequest::COMPLETED, 'completed_at' => now()]);

            return $live;
        });
    }

    private function cancelOn(Service $service, string $type): CarbonInterface
    {
        if ($type === CancellationRequest::IMMEDIATE) {
            return today();
        }

        if ($type !== CancellationRequest::END_OF_PERIOD) {
            throw new InvalidArgumentException('Choose when to cancel.');
        }

        if (! $service->billing_cycle->isRecurring() || $service->next_due_date === null) {
            throw new InvalidArgumentException('One-time services can only be cancelled straight away.');
        }

        // An overdue service has no paid time left, so it goes now.
        return $service->next_due_date->max(today());
    }

    /**
     * Takes the service's (and its add-ons') charges off open invoices with
     * nothing paid: an invoice with nothing else on it is cancelled, and the
     * lines come off one shared with other services. Invoices with a payment
     * on them are left for staff.
     */
    private function dropUnpaidCharges(Service $service): void
    {
        $ids = $service->addons()->pluck('id')->push($service->id)->all();

        $invoices = Invoice::whereIn('status', [InvoiceStatus::Draft, InvoiceStatus::Unpaid])
            ->whereHas('items', fn ($items) => $items->whereIn('service_id', $ids))
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice) => $invoice->amountPaid() === 0);

        foreach ($invoices as $invoice) {
            if ($invoice->items()->whereNotNull('service_id')->whereNotIn('service_id', $ids)->exists()) {
                $invoice->items()->whereIn('service_id', $ids)->delete();
                $invoice->recalculate();
                Activity::record("Took {$service->description()} off invoice #{$invoice->number} after its cancellation request", $invoice);
            } else {
                $this->invoices->cancel($invoice);
            }
        }
    }
}
