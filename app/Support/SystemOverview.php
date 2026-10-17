<?php

namespace App\Support;

use App\Billing\CreditApplier;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The admin dashboard's chart: new orders, accepted orders and income, by
 * hour for today, by day for the last 30 days or by month for the last year.
 */
class SystemOverview
{
    public const TODAY = 'today';

    public const MONTH = '30days';

    public const YEAR = 'year';

    public const PERIODS = [self::TODAY => 'Today', self::MONTH => 'Last 30 Days', self::YEAR => 'Last 1 Year'];

    /** @var list<CarbonImmutable> The start of each bucket. */
    public readonly array $buckets;

    public function __construct(public readonly string $period, ?CarbonImmutable $now = null)
    {
        $now ??= CarbonImmutable::now();

        $this->buckets = match ($period) {
            self::YEAR => array_map(fn (int $i) => $now->startOfMonth()->subMonths(11 - $i), range(0, 11)),
            self::MONTH => array_map(fn (int $i) => $now->startOfDay()->subDays(29 - $i), range(0, 29)),
            default => array_map(fn (int $i) => $now->startOfDay()->addHours($i), range(0, $now->hour)),
        };
    }

    public static function fromRequest(?string $period): self
    {
        return new self(array_key_exists((string) $period, self::PERIODS) ? $period : self::TODAY);
    }

    /** @return list<string> */
    public function labels(): array
    {
        $format = match ($this->period) {
            self::YEAR => 'M Y',
            self::MONTH => 'M j',
            default => 'ga',
        };

        return array_map(fn (CarbonImmutable $start) => $start->format($format), $this->buckets);
    }

    /** @return list<int> Orders placed in each bucket. */
    public function newOrders(): array
    {
        return $this->count(Order::where('created_at', '>=', $this->buckets[0])->pluck('created_at'));
    }

    /** @return list<int> Orders accepted in each bucket. */
    public function activatedOrders(): array
    {
        $accepted = Order::where('status', OrderStatus::Active)
            ->where(fn ($query) => $query->where('reviewed_at', '>=', $this->buckets[0])
                ->orWhere(fn ($query) => $query->whereNull('reviewed_at')->where('created_at', '>=', $this->buckets[0])))
            ->get(['reviewed_at', 'created_at'])
            ->map(fn (Order $order) => $order->reviewed_at ?? $order->created_at);

        return $this->count($accepted);
    }

    /** @return list<int> Money received in each bucket, in minor units. */
    public function income(): array
    {
        $totals = array_fill(0, count($this->buckets), 0);

        // Paying from credit moves money already received, so it isn't new income.
        $payments = Transaction::where('created_at', '>=', $this->buckets[0])
            ->where('gateway', '!=', CreditApplier::GATEWAY)->get(['created_at', 'amount']);

        foreach ($payments as $payment) {
            $totals[$this->bucketOf($payment->created_at)] += (int) $payment->amount;
        }

        return $totals;
    }

    /** @return list<int> */
    private function count(Collection $times): array
    {
        $counts = array_fill(0, count($this->buckets), 0);

        foreach ($times as $time) {
            $counts[$this->bucketOf($time)]++;
        }

        return $counts;
    }

    private function bucketOf($time): int
    {
        $time = CarbonImmutable::parse($time);

        for ($i = count($this->buckets) - 1; $i > 0; $i--) {
            if ($time->gte($this->buckets[$i])) {
                return $i;
            }
        }

        return 0;
    }
}
