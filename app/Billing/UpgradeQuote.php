<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ServiceOption;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What moving a service to another product costs for the rest of the period
 * it has paid for: the new product's price for the days left, less the
 * unused part of what was paid. A move that also changes the billing cycle
 * starts a whole new period of the new cycle today instead.
 */
readonly class UpgradeQuote
{
    /** @param  Collection<int, ServiceOption>  $options */
    public function __construct(
        public Product $product,
        public Collection $options,
        public BillingCycle $cycle,
        public int $recurring,
        public int $recurringDiscount,
        public Proration $proration,
        /** The new next due date, when the move changes it. */
        public ?CarbonInterface $nextDue = null,
    ) {}

    /** Positive is owed by the client, negative goes to their credit. */
    public function net(): int
    {
        return $this->proration->charge - $this->proration->credit;
    }

    public function newNetRecurring(): int
    {
        return max(0, $this->recurring - $this->recurringDiscount);
    }
}
