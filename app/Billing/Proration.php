<?php

namespace App\Billing;

/**
 * The money side of a move: the unused part of what the client paid for
 * the current period, and what the new product costs up to the next due
 * date.
 */
readonly class Proration
{
    public function __construct(
        public int $daysLeft = 0,
        public int $periodDays = 0,
        public int $credit = 0,
        public int $charge = 0,
    ) {}
}
