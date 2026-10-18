<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Payments\GatewayRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Automatic charging, run nightly. An unpaid invoice is charged to the
 * client's default saved method billing.auto_charge_days_before_due days
 * before its due date (0: on the day), and again each number of days after
 * the due date in billing.charge_retry_days while it stays unpaid. Like
 * overdue reminders, a missed night catches up with one charge, not
 * several, and an invoice is never charged automatically twice on one day.
 * Clients who turned automatic charging off are left alone.
 */
class AutoCharger
{
    /**
     * How long after the last retry day a missed run still catches up, so a
     * billing run that was down for a few days doesn't skip invoices, while
     * turning automatic charging on doesn't charge long-overdue ones.
     */
    private const CATCH_UP_DAYS = 30;

    public function __construct(private SavedMethodCharger $charger, private GatewayRegistry $gateways) {}

    /** @return array{succeeded: int, failed: int, pending: int} */
    public function run(CarbonInterface $today): array
    {
        $counts = [PaymentAttempt::SUCCEEDED => 0, PaymentAttempt::FAILED => 0, PaymentAttempt::PENDING => 0];
        $gateways = $this->gateways->savingMethods()->map->key()->all();

        if ($gateways === []) {
            return $counts;
        }

        // Days from the due date to each try: the first one is before or on it.
        $tries = self::tries();

        Invoice::where('status', InvoiceStatus::Unpaid)
            ->whereDate('due_date', '<=', $today->copy()->subDays($tries->first()))
            ->whereDate('due_date', '>=', $today->copy()->subDays($tries->last() + self::CATCH_UP_DAYS))
            ->whereHas('client', fn ($client) => $client->where('auto_charge', true)
                ->whereHas('paymentMethods', fn ($methods) => $methods->where('is_default', true)->whereIn('gateway', $gateways)))
            ->with('client')
            ->chunkById(100, function ($invoices) use ($today, $tries, $gateways, &$counts) {
                foreach ($invoices as $invoice) {
                    if (! $this->due($invoice, $today, $tries) || $invoice->balance() <= 0) {
                        continue;
                    }

                    $method = $invoice->client->defaultPaymentMethod();

                    if ($method === null || ! in_array($method->gateway, $gateways, true)) {
                        continue;
                    }

                    try {
                        $counts[$this->charger->charge($invoice, $method, PaymentAttempt::AUTO)->status]++;
                    } catch (InvalidArgumentException) {
                        // Paid or being charged by staff in the meantime.
                    }
                }
            });

        return $counts;
    }

    /**
     * Days after the due date of each automatic try, in order. The first is
     * negative when invoices are charged before they're due.
     *
     * @return Collection<int, int>
     */
    public static function tries(): Collection
    {
        $first = -max(0, (int) config('billing.auto_charge_days_before_due', 0));
        $retries = collect((array) config('billing.charge_retry_days', []))->map(fn ($day) => (int) $day)->filter(fn (int $day) => $day > 0);

        return $retries->prepend($first)->unique()->sort()->values();
    }

    /** Whether the invoice has passed a try day with no automatic charge since. */
    private function due(Invoice $invoice, CarbonInterface $today, Collection $tries): bool
    {
        $days = (int) $invoice->due_date->diffInDays($today, false);
        $passed = $tries->filter(fn (int $try) => $days >= $try)->last();

        // A charge the gateway is still processing may yet go through.
        if ($passed === null || $invoice->paymentAttempts()->where('status', PaymentAttempt::PENDING)->exists()) {
            return false;
        }

        return ! $invoice->paymentAttempts()
            ->where('source', PaymentAttempt::AUTO)
            ->whereDate('attempted_on', '>=', $invoice->due_date->copy()->addDays($passed))
            ->exists();
    }
}
