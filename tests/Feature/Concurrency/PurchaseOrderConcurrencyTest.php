<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Recebimento e cancelamento de pedido de compra sob concorrência real. */
class PurchaseOrderConcurrencyTest extends ConcurrencyTestCase
{
    private function order(Part $part, int $quantity, int $cost, User $admin): array
    {
        $order = app(CreatePurchaseOrder::class)->handle([
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['part_id' => $part->id, 'quantity' => $quantity, 'unit_cost_cents' => $cost]],
        ], $admin);

        return [$order, (int) $order->items()->value('id')];
    }

    public function test_two_simultaneous_receipts_cannot_together_exceed_the_order(): void
    {
        $admin = User::factory()->create();
        $part = Part::factory()->create(['on_hand' => 0]);
        [$order, $item] = $this->order($part, 5, 100, $admin);
        $lines = [['purchase_order_item_id' => $item, 'quantity' => 3]];

        $result = $this->whileInFlight(
            fn () => app(ReceivePurchaseOrder::class)->handle($order, $lines, $admin),
            'po_receive', ['order_id' => $order->id, 'lines' => $lines, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'OVER_RECEIPT'], $result, 'a segunda vê os 3 já recebidos: faltam 2');
        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame(3, (int) InventoryMovement::sum('quantity'));
        $this->assertSame('partially_received', $order->fresh()->status->value);
    }

    public function test_cancelling_while_a_receipt_is_in_flight_waits_and_refuses_when_the_order_is_complete(): void
    {
        $admin = User::factory()->create();
        $part = Part::factory()->create(['on_hand' => 0]);
        [$order, $item] = $this->order($part, 5, 100, $admin);

        $result = $this->whileInFlight(
            fn () => app(ReceivePurchaseOrder::class)->handle($order, [['purchase_order_item_id' => $item, 'quantity' => 5]], $admin),
            'po_cancel', ['order_id' => $order->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame('received', $order->fresh()->status->value);
        $this->assertSame(5, $part->fresh()->on_hand);
    }

    public function test_receiving_while_a_cancellation_is_in_flight_is_refused_and_moves_no_stock(): void
    {
        $admin = User::factory()->create();
        $part = Part::factory()->create(['on_hand' => 0]);
        [$order, $item] = $this->order($part, 5, 100, $admin);

        $result = $this->whileInFlight(
            fn () => app(CancelPurchaseOrder::class)->handle($order, 'Desistência', $admin),
            'po_receive', ['order_id' => $order->id, 'lines' => [['purchase_order_item_id' => $item, 'quantity' => 2]], 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame('canceled', $order->fresh()->status->value);
        $this->assertSame(0, $part->fresh()->on_hand);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_receipts_of_two_orders_for_the_same_part_are_both_counted_and_average_in_order(): void
    {
        $admin = User::factory()->create();
        $part = Part::factory()->create(['on_hand' => 0]);
        [$first, $firstItem] = $this->order($part, 10, 100, $admin);
        [$second, $secondItem] = $this->order($part, 10, 200, $admin);

        $result = $this->whileInFlight(
            fn () => app(ReceivePurchaseOrder::class)->handle($first, [['purchase_order_item_id' => $firstItem, 'quantity' => 10]], $admin),
            'po_receive', ['order_id' => $second->id, 'lines' => [['purchase_order_item_id' => $secondItem, 'quantity' => 10]], 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $fresh = $part->fresh();
        $this->assertSame([20, 150], [$fresh->on_hand, $fresh->average_cost_cents], 'a segunda esperou o lock da peça e viu o custo médio da primeira');
        $this->assertSame(['received', 'received'], PurchaseOrder::orderBy('id')->get()->map(fn ($o) => $o->status->value)->all());
        $this->assertSame(2, DB::table('inventory_movements')->whereNotNull('purchase_order_item_id')->count());
    }

    public function test_receiving_locks_the_parts_in_ascending_id_order_whatever_the_order_of_the_lines(): void
    {
        // Sem a ordenação por peça, dois pedidos que compartilham A < B e mandam as linhas em ordens opostas se cruzam.
        $admin = User::factory()->create();
        $low = Part::factory()->create(['on_hand' => 0]);
        $high = Part::factory()->create(['on_hand' => 0]);
        $order = app(CreatePurchaseOrder::class)->handle([
            'supplier_id' => Supplier::factory()->create()->id,
            'items' => [['part_id' => $high->id, 'quantity' => 1, 'unit_cost_cents' => 100], ['part_id' => $low->id, 'quantity' => 1, 'unit_cost_cents' => 100]],
        ], $admin);
        $ids = $order->items()->pluck('id', 'part_id');
        $lines = [['purchase_order_item_id' => $ids[$high->id], 'quantity' => 1], ['purchase_order_item_id' => $ids[$low->id], 'quantity' => 1]];   // a maior primeiro
        $highWasFree = null;

        $result = $this->whileInFlight(
            fn () => DB::select('select id from parts where id = ? for update', [$low->id]),
            'po_receive', ['order_id' => $order->id, 'lines' => $lines, 'actor_id' => $admin->id],
            function () use ($high, &$highWasFree) {
                try {
                    DB::transaction(fn () => DB::select('select id from parts where id = ? for update nowait', [$high->id]));
                    $highWasFree = true;
                } catch (QueryException) {
                    $highWasFree = false;
                }
            },
        );

        $this->assertTrue($highWasFree, 'o recebimento travou a peça de maior ID antes de esperar a de menor ID');
        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame([1, 1], [$low->fresh()->on_hand, $high->fresh()->on_hand]);
    }
}
