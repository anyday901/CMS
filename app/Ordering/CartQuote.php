<?php

namespace App\Ordering;

use App\Billing\PricedLine;
use App\Models\Promotion;

/** What the cart costs now, line by line, before tax. */
readonly class CartQuote
{
    /**
     * @param  list<array{key: string, priced: PricedLine, discount: int, addon: bool}>  $rows
     * @param  array<string, string>  $errors  Problems by cart line key; those lines aren't priced.
     */
    public function __construct(
        public string $currency,
        public array $rows,
        public array $errors,
        public ?Promotion $promotion,
        public ?string $promotionError,
    ) {}

    public function subtotal(): int
    {
        return array_sum(array_map(fn (array $row) => $row['priced']->recurring + $row['priced']->setupFee, $this->rows));
    }

    public function discount(): int
    {
        return array_sum(array_column($this->rows, 'discount'));
    }

    public function total(): int
    {
        return $this->subtotal() - $this->discount();
    }
}
