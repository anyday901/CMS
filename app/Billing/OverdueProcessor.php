<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Suspends and terminates services whose invoices stay unpaid past the
 * configured number of days after the due date. Clients in a group exempt
 * from suspension are left alone, and so are clients whose unpaid invoices
 * come to no more than their credit limit.
 */
class OverdueProcessor
{
    /** @var array<int, bool> Whether each client is within their credit limit, worked out once a run. */
    private array $withinLimit = [];

    public function __construct(private ServiceLifecycle $lifecycle) {}

    /** @return array{suspended: int, terminated: int} */
    public function run(CarbonInterface $today): array
    {
        $counts = ['suspended' => 0, 'terminated' => 0];
        $this->withinLimit = [];

        $terminateAfter = config('billing.terminate_after_days');
        if ($terminateAfter !== null) {
            $services = $this->overdueServices($today, (int) $terminateAfter, ServiceStatus::billable());
            foreach ($services as $service) {
                // An add-on may already have gone with its service.
                if (! in_array($service->refresh()->status, ServiceStatus::billable(), true)) {
                    continue;
                }
                $this->lifecycle->terminate($service);
                $counts['terminated']++;
            }
        }

        $suspendAfter = config('billing.suspend_after_days');
        if ($suspendAfter !== null) {
            $services = $this->overdueServices($today, (int) $suspendAfter, [ServiceStatus::Active]);
            foreach ($services as $service) {
                if ($service->refresh()->status !== ServiceStatus::Active) {
                    continue;
                }
                $this->lifecycle->suspend($service, ServiceLifecycle::REASON_OVERDUE);
                $counts['suspended']++;
            }
        }

        return $counts;
    }

    private function overdueServices(CarbonInterface $today, int $days, array $statuses)
    {
        $dueBefore = $today->copy()->subDays($days);

        return Service::query()
            ->whereIn('status', $statuses)
            ->whereHas('invoiceItems.invoice', fn (Builder $invoice) => $invoice
                ->where('status', InvoiceStatus::Unpaid)
                ->whereDate('due_date', '<=', $dueBefore))
            ->whereDoesntHave('client.group', fn (Builder $group) => $group->where('exempt_suspension', true))
            ->with('client.group')
            ->get()
            ->reject(fn (Service $service) => $this->withinLimit[$service->client_id] ??= $service->client->withinCreditLimit())
            ->values();
    }
}
