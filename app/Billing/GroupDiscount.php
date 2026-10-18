<?php

namespace App\Billing;

use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;

/**
 * A client group's percent off a service, as its own invoice line. It's
 * taken from what's left after any promotion code, so the two stack without
 * the total going below zero. Setup fees aren't discounted.
 */
class GroupDiscount
{
    /**
     * Adds the group's discount on $amount to the invoice, under the service's
     * other lines. Returns the amount taken off (0 without a discount).
     */
    public static function addTo(Invoice $invoice, Service $service, ?ClientGroup $group, int $amount, bool $taxable): int
    {
        $discount = $group?->discountOn($amount) ?? 0;

        if ($discount > 0) {
            $invoice->items()->create([
                'service_id' => $service->id,
                'type' => InvoiceItem::TYPE_GROUP_DISCOUNT,
                'description' => InvoiceItem::groupDiscountDescription($service, $group),
                'amount' => -$discount,
                'taxable' => $taxable,
            ]);
        }

        return $discount;
    }
}
