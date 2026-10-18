<?php

namespace App\Models\Concerns;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Currency;
use App\Support\Money;
use InvalidArgumentException;

/**
 * How much a client may owe on unpaid invoices before overdue suspension
 * kicks in. The client's own limit wins; without one their group's applies,
 * then the default under Automation. Group and default limits are set in
 * the default currency and converted to the client's.
 *
 * @mixin Client
 */
trait HasCreditLimit
{
    public const CREDIT_LIMIT_CLIENT = 'client';

    public const CREDIT_LIMIT_GROUP = 'group';

    public const CREDIT_LIMIT_DEFAULT = 'default';

    public function initializeHasCreditLimit(): void
    {
        $this->mergeCasts(['credit_limit' => 'integer']);
    }

    /** The limit in the client's currency, or null when there is none. */
    public function creditLimit(): ?int
    {
        return match ($this->creditLimitSource()) {
            self::CREDIT_LIMIT_CLIENT => $this->credit_limit,
            self::CREDIT_LIMIT_GROUP => $this->inClientCurrency($this->group->credit_limit),
            self::CREDIT_LIMIT_DEFAULT => $this->inClientCurrency(self::defaultCreditLimit()),
            default => null,
        };
    }

    /** Where the limit comes from: the client, their group or the default; null when there is none. */
    public function creditLimitSource(): ?string
    {
        return match (true) {
            $this->credit_limit !== null => self::CREDIT_LIMIT_CLIENT,
            $this->group?->credit_limit !== null => self::CREDIT_LIMIT_GROUP,
            self::defaultCreditLimit() !== null => self::CREDIT_LIMIT_DEFAULT,
            default => null,
        };
    }

    /** What's left to pay on the client's unpaid invoices. */
    public function unpaidBalance(): int
    {
        return (int) $this->invoices()
            ->where('status', InvoiceStatus::Unpaid)
            ->withSum('transactions as paid_amount', 'amount')
            ->get(['id', 'total'])
            ->sum(fn ($invoice) => $invoice->total - (int) $invoice->paid_amount);
    }

    /** Whether the client owes something, but no more than their limit, so isn't suspended for it. */
    public function withinCreditLimit(): bool
    {
        $limit = $this->creditLimit();

        return $limit !== null && $limit > 0 && $this->unpaidBalance() <= $limit;
    }

    /** The default limit under Automation, in cents of the default currency, or null when it's off. */
    public static function defaultCreditLimit(): ?int
    {
        $value = config('billing.credit_limit');

        try {
            return filled($value) ? Money::parse((string) $value) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function inClientCurrency(int $cents): int
    {
        return Currency::convert($cents, config('billing.currency'), $this->currency ?? config('billing.currency'));
    }
}
