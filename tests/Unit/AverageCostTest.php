<?php

namespace Tests\Unit;

use App\Actions\Inventory\ReceivePart;
use PHPUnit\Framework\TestCase;

class AverageCostTest extends TestCase
{
    public function test_the_first_costed_entry_sets_the_average_ignoring_uncosted_stock(): void
    {
        $this->assertSame(500, ReceivePart::newAverageCost(0, null, 10, 500));
        $this->assertSame(500, ReceivePart::newAverageCost(7, null, 3, 500), 'as 7 unidades sem custo não pesam');
    }

    public function test_the_average_is_weighted_by_quantity(): void
    {
        $this->assertSame(150, ReceivePart::newAverageCost(10, 100, 10, 200));
        $this->assertSame(133, ReceivePart::newAverageCost(20, 100, 10, 200), '(20×100 + 10×200) ÷ 30 = 133,33');
        $this->assertSame(100, ReceivePart::newAverageCost(10, 100, 5, 100));
    }

    public function test_it_rounds_half_up_in_integer_cents(): void
    {
        $this->assertSame(151, ReceivePart::newAverageCost(1, 100, 1, 202), '151,0');
        $this->assertSame(152, ReceivePart::newAverageCost(1, 100, 1, 203), '151,5 sobe');
        $this->assertSame(125, ReceivePart::newAverageCost(3, 100, 1, 200), '125,0');
        $this->assertSame(133, ReceivePart::newAverageCost(2, 100, 1, 200), '133,33 desce');
        $this->assertSame(167, ReceivePart::newAverageCost(1, 100, 2, 200), '166,67 sobe');
    }

    public function test_it_handles_the_largest_values_without_overflow(): void
    {
        // saldo máximo × custo máximo: 1.000.000 × 100.000.000 cabe em 64 bits.
        $this->assertSame(100_000_000, ReceivePart::newAverageCost(1_000_000, 100_000_000, 1, 100_000_000));
        $this->assertSame(0, ReceivePart::newAverageCost(1_000_000, 0, 1_000_000, 0));
    }
}
