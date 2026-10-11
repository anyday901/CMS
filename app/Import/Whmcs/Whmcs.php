<?php

namespace App\Import\Whmcs;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\InvoiceItem;

/** Converts WHMCS column values to this app's values. */
class Whmcs
{
    /** Price columns in tblpricing, by billing cycle. */
    public const PRICE_COLUMNS = [
        'monthly' => ['monthly', 'msetupfee'],
        'quarterly' => ['quarterly', 'qsetupfee'],
        'semi_annually' => ['semiannually', 'ssetupfee'],
        'annually' => ['annually', 'asetupfee'],
        'biennially' => ['biennially', 'bsetupfee'],
        'triennially' => ['triennially', 'tsetupfee'],
    ];

    /** "12.345" -> 1235. Decimal strings are read digit by digit, never as floats. */
    public static function cents(mixed $value): int
    {
        $value = trim((string) $value);

        if (! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $value, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return 0;
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $cents = (int) ($m[2] ?: '0') * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** A date, or null for blanks and MySQL zero dates. */
    public static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') ? null : substr($value, 0, 10);
    }

    public static function dateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }

        return strlen($value) === 10 ? "{$value} 00:00:00" : substr($value, 0, 19);
    }

    /** WHMCS stores most text HTML-escaped. */
    public static function text(mixed $value): string
    {
        return trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** "internal|Shown name" -> "Shown name", the format WHMCS uses for option and field names. */
    public static function label(mixed $value): string
    {
        $text = self::text($value);
        $pipe = strpos($text, '|');

        return $pipe === false ? $text : trim(substr($text, $pipe + 1));
    }

    public static function on(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', '1', 'yes', 'true'], true);
    }

    /** Null when WHMCS uses a cycle this app does not have. */
    public static function cycle(mixed $value): ?BillingCycle
    {
        return match (strtolower(str_replace([' ', '-'], '', (string) $value))) {
            'monthly' => BillingCycle::Monthly,
            'quarterly' => BillingCycle::Quarterly,
            'semiannually' => BillingCycle::SemiAnnually,
            'annually' => BillingCycle::Annually,
            'biennially' => BillingCycle::Biennially,
            'triennially' => BillingCycle::Triennially,
            'onetime', 'freeaccount', 'free' => BillingCycle::OneTime,
            default => null,
        };
    }

    public static function clientStatus(mixed $value): ClientStatus
    {
        return match (strtolower((string) $value)) {
            'inactive' => ClientStatus::Inactive,
            'closed' => ClientStatus::Closed,
            default => ClientStatus::Active,
        };
    }

    public static function serviceStatus(mixed $value): ServiceStatus
    {
        return match (strtolower((string) $value)) {
            'pending' => ServiceStatus::Pending,
            'suspended' => ServiceStatus::Suspended,
            'terminated' => ServiceStatus::Terminated,
            'cancelled', 'fraud' => ServiceStatus::Cancelled,
            default => ServiceStatus::Active,
        };
    }

    public static function invoiceStatus(mixed $value): InvoiceStatus
    {
        return match (strtolower((string) $value)) {
            'draft' => InvoiceStatus::Draft,
            'paid' => InvoiceStatus::Paid,
            'cancelled' => InvoiceStatus::Cancelled,
            'refunded' => InvoiceStatus::Refunded,
            default => InvoiceStatus::Unpaid,
        };
    }

    public static function itemType(mixed $value): string
    {
        return match (strtolower((string) $value)) {
            'hosting' => InvoiceItem::TYPE_SERVICE,
            'setup' => InvoiceItem::TYPE_SETUP_FEE,
            'latefee' => InvoiceItem::TYPE_LATE_FEE,
            default => InvoiceItem::TYPE_MANUAL,
        };
    }

    public static function ticketStatus(mixed $value): TicketStatus
    {
        return match (strtolower(str_replace([' ', '-'], '', (string) $value))) {
            'answered' => TicketStatus::Answered,
            'customerreply' => TicketStatus::CustomerReply,
            'onhold' => TicketStatus::OnHold,
            'closed' => TicketStatus::Closed,
            default => TicketStatus::Open,
        };
    }

    public static function priority(mixed $value): TicketPriority
    {
        return match (strtolower((string) $value)) {
            'low' => TicketPriority::Low,
            'high' => TicketPriority::High,
            'urgent', 'critical' => TicketPriority::Urgent,
            default => TicketPriority::Medium,
        };
    }

    /** WHMCS gateway module names for the gateways this app has. */
    public static function gateway(mixed $value): string
    {
        $gateway = strtolower(trim((string) $value));

        return match (true) {
            $gateway === '' => 'manual',
            str_starts_with($gateway, 'paypal') => 'paypal',
            $gateway === 'banktransfer' => 'bank_transfer',
            $gateway === 'mailin' => 'check',
            default => $gateway,
        };
    }

    /** "482913_report.pdf" -> "report.pdf": WHMCS prefixes stored attachment names with a random number. */
    public static function attachmentName(string $stored): string
    {
        return preg_replace('/^\d+_/', '', $stored) ?: $stored;
    }
}
