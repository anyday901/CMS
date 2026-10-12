<?php

namespace App\Billing;

use App\Models\ServiceOption;
use Illuminate\Support\Collection;

/** An order line with its price worked out, before any discount. */
readonly class PricedLine
{
    /** @param  Collection<int, ServiceOption>  $options */
    public function __construct(
        public OrderLine $line,
        public Collection $options,
        public int $recurring,
        public int $setupFee,
    ) {}
}
