<?php

namespace App\Billing;

use App\Models\Product;
use App\Models\ServiceOption;
use Illuminate\Support\Collection;

/**
 * What moving a service to another product costs for the rest of the period
 * it has paid for: the new product's price for the days left, less the
 * unused part of what was paid.
 */
readonly class UpgradeQuote
{
    /** @param  Collection<int, ServiceOption>  $options */
    public function __construct(
        public Product $product,
        public Collection $options,
        public int $recurring,
        public int $recurringDiscount,
        public int $daysLeft,
        public int $periodDays,
        public int $credit,
        public int $charge,
    ) {}

    /** Positive is owed by the client, negative goes to their credit. */
    public function net(): int
    {
        return $this->charge - $this->credit;
    }

    public function newNetRecurring(): int
    {
        return max(0, $this->recurring - $this->recurringDiscount);
    }
}
