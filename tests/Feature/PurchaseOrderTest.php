<?php

namespace Tests\Feature;

use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Pedido de compra e recebimento parcial contra o pedido (PLANO §13.3). */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->supplier = Supplier::factory()->create(['name' => 'Peças Sul']);
    }

    protected function tearDown(): void
    {
        InventoryMovement::flushEventListeners();

        parent::tearDown();
    }

    private function part(array $attrs = []): Part
    {
        return Part::factory()->create($attrs + ['on_hand' => 0]);
    }

    /** @param  array<int, array{0: Part, 1: int, 2: int}>  $lines  [peça, quantidade, custo] */
    private function order(array $lines, ?Supplier $supplier = null): int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/purchase-orders', [
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'items' => array_map(fn ($l) => ['part_id' => $l[0]->id, 'quantity' => $l[1], 'unit_cost_cents' => $l[2]], $lines),
        ])->assertCreated()->json('data.id');
    }

    private function receive(int $orderId, array $lines)
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/purchase-orders/{$orderId}/receipts", ['items' => $lines]);
    }

    private function itemId(int $orderId, Part $part): int
    {
        return DB::table('purchase_order_items')->where('purchase_order_id', $orderId)->where('part_id', $part->id)->value('id');
    }

    public function test_creating_an_order_records_supplier_items_and_totals_and_touches_no_stock(): void
    {
        $a = $this->part(['name' => 'Fonte']);
        $b = $this->part(['name' => 'Cabo']);

        $res = $this->actingAs($this->admin)->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'notes' => 'Urgente',
            'items' => [['part_id' => $a->id, 'quantity' => 10, 'unit_cost_cents' => 500], ['part_id' => $b->id, 'quantity' => 4, 'unit_cost_cents' => 250]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')->assertJsonPath('data.status_label', 'Aberto')->assertJsonPath('data.supplier.name', 'Peças Sul')
            ->assertJsonPath('data.total_cents', 6000)->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.received_quantity', 0)->assertJsonPath('data.items.0.remaining_quantity', 10)->assertJsonPath('data.created_by.id', $this->admin->id);
        $this->assertGreaterThan(0, $res->json('data.number'));

        $this->assertSame([0, 0], [$a->fresh()->on_hand, $b->fresh()->on_hand]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_the_order_is_validated(): void
    {
        $part = $this->part();
        $inactivePart = $this->part(['active' => false]);
        $inactive = Supplier::factory()->create(['active' => false]);
        $post = fn (array $body) => $this->actingAs($this->admin)->postJson('/api/v1/purchase-orders', $body);
        $item = ['part_id' => $part->id, 'quantity' => 1, 'unit_cost_cents' => 100];

        $post(['supplier_id' => $this->supplier->id, 'items' => []])->assertUnprocessable()->assertJsonPath('errors.items.0', 'Adicione ao menos uma peça ao pedido.');
        $post(['items' => [$item]])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        $post(['supplier_id' => $inactive->id, 'items' => [$item]])->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'Fornecedor não encontrado ou inativo.');
        $post(['supplier_id' => $this->supplier->id, 'items' => [$item, $item]])->assertUnprocessable()->assertJsonValidationErrors('items.0.part_id');
        $post(['supplier_id' => $this->supplier->id, 'items' => [['part_id' => $inactivePart->id] + $item]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.part_id' => 'Peça não encontrada ou inativa.']);
        foreach ([['quantity' => 0], ['quantity' => 1_000_001], ['unit_cost_cents' => -1], ['unit_cost_cents' => 100_000_001], ['unit_cost_cents' => null]] as $bad) {
            $post(['supplier_id' => $this->supplier->id, 'items' => [array_merge($item, $bad)]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_receiving_in_two_steps_moves_stock_updates_the_average_and_the_status(): void
    {
        $a = $this->part(['name' => 'Fonte']);
        $b = $this->part(['name' => 'Cabo']);
        $id = $this->order([[$a, 10, 500], [$b, 4, 250]]);

        $this->receive($id, [['purchase_order_item_id' => $this->itemId($id, $a), 'quantity' => 6]])->assertOk()
            ->assertJsonPath('data.status', 'partially_received')->assertJsonPath('data.status_label', 'Recebido em parte')
            ->assertJsonPath('data.items.0.received_quantity', 6)->assertJsonPath('data.items.0.remaining_quantity', 4)->assertJsonPath('data.items.1.received_quantity', 0);
        $this->assertSame([6, 500], [$a->fresh()->on_hand, $a->fresh()->average_cost_cents]);
        $movement = InventoryMovement::sole();
        $this->assertSame([500, $this->supplier->id, $this->itemId($id, $a), 'receipt'], [(int) $movement->unit_cost_cents, $movement->supplier_id, $movement->purchase_order_item_id, $movement->kind->value]);
        $this->assertStringContainsString('pedido de compra', $movement->reason);

        // Segunda entrega: o resto de A com custo efetivo diferente do combinado, e todo o B.
        $this->receive($id, [
            ['purchase_order_item_id' => $this->itemId($id, $b), 'quantity' => 4],
            ['purchase_order_item_id' => $this->itemId($id, $a), 'quantity' => 4, 'unit_cost_cents' => 800],
        ])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.items.0.remaining_quantity', 0);

        $this->assertSame([10, 620], [$a->fresh()->on_hand, $a->fresh()->average_cost_cents], '(6×500 + 4×800) ÷ 10');
        $this->assertSame([4, 250], [$b->fresh()->on_hand, $b->fresh()->average_cost_cents]);
        $this->assertSame(3, InventoryMovement::count());
        $this->assertSame(800, (int) InventoryMovement::where('part_id', $a->id)->orderByDesc('id')->value('unit_cost_cents'));
    }

    public function test_it_refuses_more_than_ordered_a_foreign_item_a_repeated_item_and_a_closed_order(): void
    {
        $a = $this->part();
        $other = $this->part();
        $id = $this->order([[$a, 5, 100]]);
        $otherOrder = $this->order([[$other, 5, 100]]);
        $item = $this->itemId($id, $a);

        $this->receive($id, [['purchase_order_item_id' => $item, 'quantity' => 6]])->assertConflict()->assertJsonPath('code', 'OVER_RECEIPT');
        $this->receive($id, [['purchase_order_item_id' => $this->itemId($otherOrder, $other), 'quantity' => 1]])->assertUnprocessable()->assertJsonValidationErrors('items.0.purchase_order_item_id');
        $this->receive($id, [['purchase_order_item_id' => $item, 'quantity' => 1], ['purchase_order_item_id' => $item, 'quantity' => 1]])->assertUnprocessable();
        $this->receive($id, [])->assertUnprocessable()->assertJsonPath('errors.items.0', 'Informe ao menos um item recebido.');
        $this->receive($id, [['purchase_order_item_id' => $item, 'quantity' => 0]])->assertUnprocessable();
        $this->assertSame(0, $a->fresh()->on_hand);
        $this->assertDatabaseCount('inventory_movements', 0);

        $this->receive($id, [['purchase_order_item_id' => $item, 'quantity' => 5]])->assertOk()->assertJsonPath('data.status', 'received');
        $this->receive($id, [['purchase_order_item_id' => $item, 'quantity' => 1]])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->assertSame(5, $a->fresh()->on_hand);
    }

    public function test_a_failure_in_the_middle_of_a_receipt_rolls_every_line_back(): void
    {
        $a = $this->part();
        $b = $this->part();
        $id = $this->order([[$a, 3, 100], [$b, 3, 100]]);

        $calls = 0;
        InventoryMovement::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('falha injetada na segunda linha');
            }
        });
        try {
            app(ReceivePurchaseOrder::class)->handle(PurchaseOrder::find($id), [
                ['purchase_order_item_id' => $this->itemId($id, $a), 'quantity' => 3], ['purchase_order_item_id' => $this->itemId($id, $b), 'quantity' => 3],
            ], $this->admin);
            $this->fail('a falha injetada deveria interromper o recebimento');
        } catch (RuntimeException) {
        }
        InventoryMovement::flushEventListeners();

        $this->assertSame(2, $calls);
        $this->assertSame([0, 0], [$a->fresh()->on_hand, $b->fresh()->on_hand]);
        $this->assertNull($a->fresh()->average_cost_cents);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame('open', PurchaseOrder::find($id)->status->value);
    }

    public function test_a_balance_over_the_ceiling_is_reported_on_the_line_and_rolls_everything_back(): void
    {
        $full = $this->part(['name' => 'Cheia', 'on_hand' => 999_999]);
        $other = $this->part();
        $id = $this->order([[$other, 2, 100], [$full, 5, 100]]);

        $this->receive($id, [['purchase_order_item_id' => $this->itemId($id, $other), 'quantity' => 2], ['purchase_order_item_id' => $this->itemId($id, $full), 'quantity' => 5]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity');

        $this->assertSame(0, $other->fresh()->on_hand, 'a linha anterior também foi desfeita');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_cancelling_keeps_what_already_arrived_and_closes_the_order(): void
    {
        $a = $this->part();
        $id = $this->order([[$a, 10, 100]]);
        $this->receive($id, [['purchase_order_item_id' => $this->itemId($id, $a), 'quantity' => 4]])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/purchase-orders/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/purchase-orders/{$id}/cancel", ['reason' => 'Fornecedor sem estoque'])->assertOk()
            ->assertJsonPath('data.status', 'canceled')->assertJsonPath('data.cancellation_reason', 'Fornecedor sem estoque')->assertJsonPath('data.items.0.received_quantity', 4);

        $this->assertSame(4, $a->fresh()->on_hand, 'o que entrou continua no estoque');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->receive($id, [['purchase_order_item_id' => $this->itemId($id, $a), 'quantity' => 1]])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->postJson("/api/v1/purchase-orders/{$id}/cancel", ['reason' => 'Outra vez'])->assertConflict();
        $this->assertSame(4, $a->fresh()->on_hand);
    }

    public function test_a_received_order_cannot_be_cancelled_and_an_unreceived_one_can(): void
    {
        $a = $this->part();
        $done = $this->order([[$a, 1, 100]]);
        $this->receive($done, [['purchase_order_item_id' => $this->itemId($done, $a), 'quantity' => 1]])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/purchase-orders/{$done}/cancel", ['reason' => 'Tarde demais'])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $open = $this->order([[$this->part(), 1, 100]]);
        $this->postJson("/api/v1/purchase-orders/{$open}/cancel", ['reason' => 'Mudou de ideia'])->assertOk()->assertJsonPath('data.status', 'canceled');
    }

    public function test_receiving_from_a_supplier_deactivated_after_the_order_still_works(): void
    {
        $a = $this->part();
        $id = $this->order([[$a, 2, 100]]);
        $this->supplier->update(['active' => false]);

        $this->receive($id, [['purchase_order_item_id' => $this->itemId($id, $a), 'quantity' => 2]])->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame($this->supplier->id, InventoryMovement::sole()->supplier_id);
    }

    public function test_the_list_filters_and_the_detail_are_for_the_admin_only(): void
    {
        $a = $this->part();
        $first = $this->order([[$a, 2, 300]]);
        $other = Supplier::factory()->create();
        $second = $this->order([[$this->part(), 1, 100]], $other);
        $this->postJson("/api/v1/purchase-orders/{$second}/cancel", ['reason' => 'Sem estoque'])->assertOk();

        $this->getJson('/api/v1/purchase-orders')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.total_cents', 600);
        $this->getJson('/api/v1/purchase-orders?status=canceled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second);
        $this->getJson("/api/v1/purchase-orders?supplier_id={$other->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/purchase-orders?status=nope')->assertUnprocessable();
        $number = PurchaseOrder::find($first)->number;
        $this->getJson("/api/v1/purchase-orders?number={$number}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);

        $tech = User::factory()->technician()->create();
        $this->actingAs($tech)->getJson('/api/v1/purchase-orders')->assertForbidden();
        $this->getJson("/api/v1/purchase-orders/{$first}")->assertForbidden();
        $this->postJson('/api/v1/purchase-orders', [])->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$first}/receipts", [])->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$first}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_the_database_keeps_the_order_and_the_receipts_coherent_even_without_the_action(): void
    {
        $a = $this->part();
        $b = $this->part();
        $id = $this->order([[$a, 5, 100]]);
        $item = $this->itemId($id, $a);
        $row = fn (array $o) => $o + ['part_id' => $a->id, 'kind' => 'receipt', 'quantity' => 1, 'unit_cost_cents' => 100, 'supplier_id' => $this->supplier->id, 'purchase_order_item_id' => $item, 'reason' => 'x', 'created_by' => $this->admin->id, 'created_at' => now()];
        $fails = function (string $why, callable $do, string $needle) {
            try {
                DB::transaction($do);
                $this->fail("deveria recusar: {$why}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $why);
            }
        };
        $insert = fn (array $o) => fn () => DB::table('inventory_movements')->insert($row($o));

        $fails('acima do pedido', $insert(['quantity' => 6]), 'would total 6 of 5');
        $fails('outra peça', $insert(['part_id' => $b->id]), 'must match the part and the supplier');
        $fails('outro fornecedor', $insert(['supplier_id' => Supplier::factory()->create()->id]), 'must match the part and the supplier');
        $fails('sem custo', $insert(['unit_cost_cents' => null]), 'inventory_movements_purchase_check');
        $fails('consumo com vínculo de pedido', $insert(['kind' => 'consumption', 'reservation_id' => 1]), 'inventory_movements_receipt_only_check');

        DB::table('inventory_movements')->insert($row(['quantity' => 3]));
        DB::table('inventory_movements')->insert($row(['quantity' => 2]));       // 3 + 2 = 5: cabe
        $fails('soma acima do pedido', $insert(['quantity' => 1]), 'would total 6 of 5');

        $fails('quantidade zero no item', fn () => DB::table('purchase_order_items')->insert(['purchase_order_id' => $id, 'part_id' => $b->id, 'quantity' => 0, 'unit_cost_cents' => 1]), 'purchase_order_items_quantity_check');
        $fails('peça repetida no pedido', fn () => DB::table('purchase_order_items')->insert(['purchase_order_id' => $id, 'part_id' => $a->id, 'quantity' => 1, 'unit_cost_cents' => 1]), 'purchase_order_items_purchase_order_id_part_id_unique');
        $fails('estado desconhecido', fn () => DB::table('purchase_orders')->where('id', $id)->update(['status' => 'x']), 'purchase_orders_status_check');
        $fails('cancelado sem motivo', fn () => DB::table('purchase_orders')->where('id', $id)->update(['status' => 'canceled', 'canceled_at' => now(), 'canceled_by' => $this->admin->id]), 'purchase_orders_cancellation_check');
    }
}
