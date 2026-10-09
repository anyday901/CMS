<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Suspends and terminates services whose invoices stay unpaid past the
 * configured number of days after the due date.
 */
class OverdueProcessor
{
    public function __construct(private ServiceLifecycle $lifecycle) {}

    /** @return array{suspended: int, terminated: int} */
    public function run(CarbonInterface $today): array
    {
        $counts = ['suspended' => 0, 'terminated' => 0];

        $terminateAfter = config('billing.terminate_after_days');
        if ($terminateAfter !== null) {
            $services = $this->overdueServices($today, (int) $terminateAfter, ServiceStatus::billable());
            foreach ($services as $service) {
                $this->lifecycle->terminate($service);
                $counts['terminated']++;
            }
        }

        $suspendAfter = config('billing.suspend_after_days');
        if ($suspendAfter !== null) {
            $services = $this->overdueServices($today, (int) $suspendAfter, [ServiceStatus::Active]);
            foreach ($services as $service) {
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
            ->get();
    }
}
