<?php

namespace App\Import\Whmcs;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\InvoiceItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /** Decoded text cut to fit a column, or null when empty. */
    public static function short(mixed $value, int $length = 250): ?string
    {
        return Str::limit(self::text($value), $length, '') ?: null;
    }

    /** A client's or contact's full name. */
    public static function name(object $row): string
    {
        return trim(self::text($row->firstname ?? '').' '.self::text($row->lastname ?? '')) ?: 'no name';
    }

    /** Product descriptions in WHMCS are HTML. */
    public static function plain(string $html): string
    {
        return trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", self::text($html)) ?? ''));
    }

    /**
     * Knowledge base articles and announcements are HTML in WHMCS; here they
     * are Markdown. Headings, paragraphs, line breaks, lists, bold, italics,
     * links, images and code come over; other tags are dropped, keeping
     * their text.
     */
    public static function markdown(string $html): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $html);
        $text = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $text);
        $decode = fn (string $value) => html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $inline = fn (string $value) => trim((string) preg_replace('/\s+/', ' ', $decode($value)));

        // Code blocks are set aside, behind a private-use character, so the clean-up below leaves them as they are.
        $blocks = [];
        $text = (string) preg_replace_callback('#<pre\b[^>]*>(.*?)</pre>#is', function ($m) use (&$blocks, $decode) {
            $blocks[] = "```\n".trim($decode((string) preg_replace('#<br\s*/?>#i', "\n", $m[1])), "\n")."\n```";

            return "\n\n\u{E000}".(count($blocks) - 1)."\u{E000}\n\n";
        }, $text);
        $text = (string) preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', fn ($m) => "\n\n".str_repeat('#', (int) $m[1]).' '.$inline($m[2])."\n\n", $text);
        $text = (string) preg_replace_callback('#<ol\b[^>]*>(.*?)</ol>#is', fn ($m) => "\n\n".preg_replace('#<li\b[^>]*>#i', "\n1. ", $m[1])."\n\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', "\n- ", $text);
        $text = (string) preg_replace_callback('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) use ($inline) {
            $label = $inline($m[3]);
            $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return preg_match('#^(https?:|mailto:|/|\#)#i', $url) && $label !== '' ? "[{$label}]({$url})" : $label;
        }, $text);
        $text = (string) preg_replace_callback('#<img\b[^>]*src\s*=\s*(["\'])(.*?)\1[^>]*>#is', function ($m) {
            $alt = preg_match('#alt\s*=\s*(["\'])(.*?)\1#is', $m[0], $a) ? trim(html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $url = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            return preg_match('#^(https?:|/)#i', $url) ? "![{$alt}]({$url})" : '';
        }, $text);
        $text = (string) preg_replace('#<(strong|b)\b[^>]*>(.*?)</\1>#is', '**$2**', $text);
        $text = (string) preg_replace('#<(em|i)\b[^>]*>(.*?)</\1>#is', '_$2_', $text);
        $text = (string) preg_replace('#<code\b[^>]*>(.*?)</code>#is', '`$1`', $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\\\n", $text);
        $text = (string) preg_replace('#</(p|div|ul|ol|table|blockquote)>#i', "\n\n", $text);
        $text = (string) preg_replace('#</(td|th)>#i', ' | ', $text);
        $text = (string) preg_replace('#</tr>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{a0}", ' ', $text);
        // Indentation from the HTML source would turn lines into code blocks.
        $text = (string) preg_replace('/^[ \t]+(?!$)/m', '', $text);
        $text = (string) preg_replace('/[ \t]+$/m', '', $text);

        $text = (string) preg_replace_callback('/\x{E000}(\d+)\x{E000}/u', fn ($m) => $blocks[(int) $m[1]], $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * A date as Eloquent writes it on this database, so imported dates
     * compare equal to ones the app saves later (SQLite keeps them as text).
     */
    public static function day(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date)->format(DB::getQueryGrammar()->getDateFormat());
    }

    /**
     * Custom field values as stored in a custom_fields column.
     *
     * @param  array<int, string>  $values  local field id => value
     */
    public static function fieldValues(array $values): ?string
    {
        return $values === [] ? null : json_encode(array_combine(array_map('strval', array_keys($values)), $values));
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
