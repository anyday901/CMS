<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\Service;

/**
 * One service to create in an order. An add-on names its service: one already
 * there ($parentService) or an earlier line of the same order ($parentLine).
 */
readonly class OrderLine
{
    public function __construct(
        public Product $product,
        public BillingCycle $cycle,
        public OrderChoices $choices = new OrderChoices,
        public ?string $label = null,
        public ?int $parentLine = null,
        public ?Service $parentService = null,
    ) {}
}
