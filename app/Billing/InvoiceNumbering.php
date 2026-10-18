<?php

namespace App\Billing;

use App\Models\Activity;
use App\Models\Invoice;
use App\Models\NumberSequence;
use Carbon\CarbonInterface;

/**
 * Sequential numbers for paid invoices only, like WHMCS's "Sequential Paid
 * Invoice Numbering". With it on, a new invoice is a proforma shown with its
 * id, and takes the next number in the format the moment it's paid, so paid
 * invoices are numbered in order with no gaps.
 */
class InvoiceNumbering
{
    /** What a number format can contain, each replaced when a number is made. */
    public const PLACEHOLDERS = ['{YEAR}', '{MONTH}', '{DAY}', '{NUMBER}'];

    public static function enabled(): bool
    {
        return (bool) config('billing.sequential_paid_numbers');
    }

    /** "INV-{YEAR}-{NUMBER}" with 42 on Oct 18, 2026 is "INV-2026-42". */
    public static function format(string $format, int $number, CarbonInterface $date): string
    {
        return str_replace(self::PLACEHOLDERS, [$date->format('Y'), $date->format('m'), $date->format('d'), (string) $number], $format);
    }

    /**
     * Why a format can't be used, or null when it can. It must have {NUMBER},
     * and a letter or symbol besides the placeholders, so a paid number can
     * never be the same as a proforma's id.
     */
    public static function problem(string $format): ?string
    {
        $literal = str_replace(self::PLACEHOLDERS, '', $format);

        return match (true) {
            ! str_contains($format, '{NUMBER}') => 'The format needs {NUMBER}, the place the number goes.',
            preg_match('/[^0-9\s]/', $literal) !== 1 => 'Add a letter or symbol, such as INV- or a dash, so paid numbers can\'t be mistaken for proforma numbers.',
            mb_strlen(self::format($format, 1, now())) > 50 => 'That format makes numbers too long. Keep them to 50 characters.',
            default => null,
        };
    }

    /**
     * Gives a proforma invoice the next paid number. Called as it is marked
     * paid, inside the payment's transaction with the invoice locked, so the
     * number is only used if the payment is. A number already taken, such as
     * by an imported invoice, is passed over.
     */
    public function assign(Invoice $invoice): void
    {
        if (! $invoice->proforma) {
            return;
        }

        $format = (string) config('billing.invoice_number_format');

        do {
            $number = self::format($format, NumberSequence::take(NumberSequence::INVOICE), now());
        } while (Invoice::where('number', $number)->exists());

        $proforma = $invoice->number;
        $invoice->forceFill(['number' => $number, 'proforma' => false])->save();
        Activity::record("Numbered proforma #{$proforma} as invoice #{$number} on payment", $invoice);
    }
}
