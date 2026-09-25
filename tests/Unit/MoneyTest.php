<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_formats_integer_cents_as_brazilian_reais(): void
    {
        $this->assertSame('R$ 0,00', Money::brl(0));
        $this->assertSame('R$ 0,05', Money::brl(5));
        $this->assertSame('R$ 12,50', Money::brl(1250));
        $this->assertSame('R$ 1.234,56', Money::brl(123456));
        $this->assertSame('R$ 1.000.000,00', Money::brl(100_000_000));
        $this->assertSame('-R$ 3,00', Money::brl(-300));
    }
}
