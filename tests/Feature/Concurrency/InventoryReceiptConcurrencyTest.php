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
}
