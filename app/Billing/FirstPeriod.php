<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * The period a new service's first invoice pays for: a free trial, a
 * prorated part period up to the product's billing day, or one whole cycle.
 */
readonly class FirstPeriod
{
    public function __construct(
        public CarbonInterface $start,
        public ?CarbonInterface $end,
        public int $amount,
        public bool $trial = false,
        public bool $prorated = false,
    ) {}

    /**
     * @param  int|null  $trialDays  Days free, or null for no trial.
     * @param  int|null  $billingDay  Day of the month (1 to 28) every service is billed on, or null.
     * @param  int|null  $extendDay  Orders on or after this day of the month also pay the next full cycle.
     */
    public static function for(
        BillingCycle $cycle,
        int $recurring,
        CarbonInterface $start,
        ?int $trialDays = null,
        ?int $billingDay = null,
        ?int $extendDay = null,
    ): self {
        if (! $cycle->isRecurring()) {
            return new self($start, null, $recurring);
        }

        if ($trialDays > 0) {
            return new self($start, $start->copy()->addDays($trialDays - 1), 0, trial: true);
        }

        return self::toBillingDay($cycle, $recurring, $start, $billingDay, $extendDay);
    }

    /** A whole cycle, or a part period up to $billingDay (and the next whole cycle from $extendDay). */
    private static function toBillingDay(BillingCycle $cycle, int $recurring, CarbonInterface $start, ?int $billingDay, ?int $extendDay): self
    {
        if ($billingDay === null || $start->day === $billingDay) {
            return new self($start, $cycle->advance($start)->subDay(), $recurring);
        }

        $due = $start->day < $billingDay
            ? $start->copy()->day($billingDay)
            : $start->copy()->addMonthNoOverflow()->day($billingDay);
        $cycleStart = $due->copy()->subMonthsNoOverflow($cycle->months());
        $amount = Money::prorate($recurring, (int) $start->diffInDays($due), (int) $cycleStart->diffInDays($due));
        $extend = $extendDay !== null && $start->day >= $extendDay;

        return new self(
            $start,
            ($extend ? $cycle->advance($due) : $due->copy())->subDay(),
            $amount + ($extend ? $recurring : 0),
            prorated: true,
        );
    }

    /** The service's next due date once this period is paid. */
    public function nextDue(): ?CarbonInterface
    {
        return $this->end?->copy()->addDay();
    }
}
