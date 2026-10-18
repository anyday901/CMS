<?php

namespace App\Models\Concerns;

use App\Billing\VatNumbers;
use App\Models\TaxRule;

/**
 * Tax on an invoice or quote. The client's level 1 and level 2 rules, their
 * tax ID and whether they're reverse charged are copied when it's created,
 * so later changes don't rewrite it. `tax` is the tax of both levels and
 * `tax2` the level 2 part of it.
 */
trait HasTaxLevels
{
    public static function bootHasTaxLevels(): void
    {
        static::creating(function (self $model) {
            $client = $model->client;

            if ($client === null) {
                return;
            }

            $model->client_tax_id ??= $client->tax_id;

            if ($model->isDirty('tax_rate')) {
                return;
            }

            $level1 = TaxRule::forClient($client);
            $level2 = TaxRule::forClient($client, 2);
            $model->tax_name = $level1?->name;
            $model->tax_rate = $level1?->rate ?? 0;
            $model->tax2_name = $level2?->name;
            $model->tax2_rate = $level2?->rate ?? 0;
            $model->tax2_compound = $level2 !== null && (bool) config('billing.tax_compound');
            $model->reverse_charge = VatNumbers::reverseCharged($client);
        });
    }

    public function initializeHasTaxLevels(): void
    {
        $this->attributes += ['tax2' => 0, 'tax2_rate' => 0, 'tax2_compound' => false, 'reverse_charge' => false];
        $this->mergeCasts(['tax2' => 'integer', 'tax2_rate' => 'integer', 'tax2_compound' => 'boolean', 'reverse_charge' => 'boolean']);
    }

    /**
     * Sets tax and total from the taxable amount. With tax-inclusive pricing
     * the lines already contain the tax, so the total is the subtotal.
     */
    protected function applyTax(int $taxable): void
    {
        [$tax1, $tax2] = TaxRule::levels($taxable, (int) $this->tax_rate, (int) $this->tax2_rate, (bool) $this->tax2_compound, (bool) $this->tax_inclusive);
        $this->tax = $tax1 + $tax2;
        $this->tax2 = $tax2;
        $this->total = $this->tax_inclusive ? $this->subtotal : $this->subtotal + $this->tax;
    }

    /** "Sales tax (8.25%)" */
    public function taxLabel(): string
    {
        return ($this->tax_name ?? 'Tax').($this->tax_rate ? ' ('.TaxRule::formatRate($this->tax_rate).')' : '');
    }

    /** "GST (5%)" */
    public function tax2Label(): string
    {
        return ($this->tax2_name ?? 'Tax').($this->tax2_rate ? ' ('.TaxRule::formatRate($this->tax2_rate).')' : '');
    }

    /**
     * The tax lines to show under the subtotal, as [label, amount]: one line
     * with a single level, as before, or one for each level that has tax.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function taxLines(): array
    {
        if (! $this->tax2_rate) {
            return [[$this->taxLabel(), (int) $this->tax]];
        }

        return array_values(array_filter([
            [$this->taxLabel(), (int) $this->tax - (int) $this->tax2],
            [$this->tax2Label(), (int) $this->tax2],
        ], fn (array $line) => $line[1] !== 0));
    }
}
