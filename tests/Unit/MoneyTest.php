<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parse(): void
    {
        $this->assertSame(1234, Money::parse('12.34'));
        $this->assertSame(1200, Money::parse('12'));
        $this->assertSame(1250, Money::parse('12.5'));
        $this->assertSame(123450, Money::parse('$1,234.50'));
        $this->assertSame(50, Money::parse('.50'));
        $this->assertSame(1999, Money::parse('19.99'));
    }

    public function test_parse_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('12.345');
    }

    public function test_format(): void
    {
        $this->assertSame('$1,234.50', Money::format(123450));
        $this->assertSame('12.34', Money::toInput(1234));
    }
}
