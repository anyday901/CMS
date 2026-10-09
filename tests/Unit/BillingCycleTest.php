<?php

namespace Tests\Unit;

use App\Enums\BillingCycle;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BillingCycleTest extends TestCase
{
    public function test_advance_does_not_overflow_month_end(): void
    {
        $jan31 = CarbonImmutable::parse('2027-01-31');

        $this->assertSame('2027-02-28', BillingCycle::Monthly->advance($jan31)->toDateString());
        $this->assertSame('2027-04-30', BillingCycle::Quarterly->advance($jan31)->toDateString());
        $this->assertSame('2028-01-31', BillingCycle::Annually->advance($jan31)->toDateString());
    }

    public function test_one_time_is_not_recurring(): void
    {
        $this->assertFalse(BillingCycle::OneTime->isRecurring());
        $this->assertNull(BillingCycle::OneTime->months());
    }
}
