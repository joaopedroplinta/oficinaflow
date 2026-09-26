<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Inventory\ReceivePart;
use App\Models\Part;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Duas entradas simultâneas na mesma peça: nenhuma pode ser perdida (atualização perdida). */
class InventoryReceiptConcurrencyTest extends ConcurrencyTestCase
{
    public function test_two_simultaneous_receipts_are_both_counted(): void
    {
        $part = Part::factory()->create(['on_hand' => 10]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReceivePart::class)->handle($part, 5, null, $admin),
            'receive', ['part_id' => $part->id, 'quantity' => 7, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame(22, $part->fresh()->on_hand, '10 + 5 + 7: nenhuma entrada se perde.');
        $this->assertSame(12, (int) DB::table('inventory_movements')->sum('quantity'));
    }

    public function test_two_simultaneous_costed_receipts_compose_the_average_in_order_without_losing_one(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReceivePart::class)->handle($part, 10, null, $admin, 100),
            'receive', ['part_id' => $part->id, 'quantity' => 10, 'actor_id' => $admin->id, 'unit_cost_cents' => 200],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $fresh = $part->fresh();
        $this->assertSame([20, 150], [$fresh->on_hand, $fresh->average_cost_cents], 'a segunda entrada esperou o lock e viu o custo médio da primeira: (10×100 + 10×200) ÷ 20');
        $this->assertSame([100, 200], DB::table('inventory_movements')->orderBy('id')->pluck('unit_cost_cents')->map(fn ($c) => (int) $c)->all());
    }
}
