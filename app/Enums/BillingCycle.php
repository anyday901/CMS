<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnually = 'semi_annually';
    case Annually = 'annually';
    case Biennially = 'biennially';

    public function months(): ?int
    {
        return match ($this) {
            self::OneTime => null,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnually => 6,
            self::Annually => 12,
            self::Biennially => 24,
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    /**
     * The start of the period after the one beginning on $date. Month-end dates
     * do not overflow, so Jan 31 monthly becomes Feb 28/29, not Mar 3.
     */
    public function advance(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->addMonthsNoOverflow($this->months() ?? 0);
    }

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'One Time',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::SemiAnnually => 'Semi-Annually',
            self::Annually => 'Annually',
            self::Biennially => 'Biennially',
        };
    }
}
