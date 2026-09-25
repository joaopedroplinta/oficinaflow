<?php

namespace Tests\Feature;

use App\Actions\Inventory\ConsumeReservations;
use App\Actions\Inventory\ReceivePart;
use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Actions\WorkOrders\CancelWorkOrder;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Estorno e cancelamento em reparo (PLANO §13.3): o consumo nunca é apagado. */
class CancelInProgressTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    protected function tearDown(): void
    {
        InventoryMovement::flushEventListeners();

        parent::tearDown();
    }

    /** Peça com saldo dado por entrada (o livro-razão explica o saldo). */
    private function stocked(int $onHand, array $attrs = []): Part
    {
        $part = Part::factory()->create(['on_hand' => 0] + $attrs);
        if ($onHand > 0) {
            app(ReceivePart::class)->handle($part, $onHand, 'Estoque inicial', $this->admin);
        }

        return $part->fresh();
    }

    /** Ordem em reparo com as peças dadas já consumidas (reservadas e iniciadas). */
    private function started(array $lines): Quote
    {
        $quote = $this->approvedOrder($lines);
        $order = WorkOrder::find($quote->work_order_id);
        app(ReserveParts::class)->handle($order, $quote->id, $this->admin);
        app(StartRepair::class)->handle($order, $quote->id, $this->admin);

        return $quote;
    }

    private function cancel(Quote $quote, array $parts = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/cancel", ['reason' => 'Cliente desistiu'] + ($parts ? ['parts' => $parts] : []));
    }

    /** on_hand == entradas + estornos − consumos, para toda peça. */
    private function assertLedgerExplainsBalance(): void
    {
        foreach (Part::all() as $part) {
            $sum = fn (string $kind) => (int) InventoryMovement::where('part_id', $part->id)->where('kind', $kind)->sum('quantity');
            $this->assertSame($sum('receipt') + $sum('return') - $sum('consumption'), $part->on_hand, "o livro-razão de {$part->sku} não explica o saldo");
        }
    }

    public function test_returning_a_consumed_part_puts_it_back_with_a_reversal_that_points_to_the_consumption(): void
    {
        $part = $this->stocked(5);
        $quote = $this->started([[$part, 2]]);
        $consumption = InventoryMovement::where('kind', 'consumption')->sole();

        $this->cancel($quote, [['part_id' => $part->id, 'action' => 'return']])->assertOk()->assertJsonPath('data.status', 'awaiting_return');

        $order = $quote->workOrder->fresh();
        $this->assertSame(WorkOrderStatus::AwaitingReturn, $order->status);
        $this->assertNotNull($order->started_at, 'o reparo chegou a começar: isso fica no histórico');
        $this->assertSame([5, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame('consumed', PartReservation::sole()->status->value, 'o consumo não é desfeito');
        $return = InventoryMovement::where('kind', 'return')->sole();
        $this->assertSame([2, $consumption->id, $part->id], [$return->quantity, $return->reverses_movement_id, $return->part_id]);
        $this->assertStringContainsString("ordem #{$order->number}", $return->reason);
        $this->assertSame(['receipt', 'consumption', 'return'], InventoryMovement::orderBy('id')->pluck('kind')->map->value->all());

        $event = $order->events()->where('type', 'canceled')->sole();
        $this->assertSame('in_progress', $event->from_status->value);
        $this->assertEquals([['part_id' => $part->id, 'quantity' => 2]], $event->metadata['returned_parts']);
        $this->assertEquals([], $event->metadata['kept_parts']);
        $this->assertStockInvariant();
        $this->assertLedgerExplainsBalance();
    }

    public function test_keeping_a_consumed_part_leaves_it_written_off_without_any_movement(): void
    {
        $part = $this->stocked(5);
        $quote = $this->started([[$part, 2]]);

        $this->cancel($quote, [['part_id' => $part->id, 'action' => 'keep']])->assertOk();

        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame(0, InventoryMovement::where('kind', 'return')->count());
        $event = $quote->workOrder->events()->where('type', 'canceled')->sole();
        $this->assertEquals([['part_id' => $part->id, 'quantity' => 2]], $event->metadata['kept_parts']);
        $this->assertEquals([], $event->metadata['returned_parts']);
        $this->assertLedgerExplainsBalance();
    }

    public function test_each_part_gets_its_own_decision_and_the_active_reservations_are_released(): void
    {
        $back = $this->stocked(5, ['name' => 'Fonte']);
        $lost = $this->stocked(5, ['name' => 'Cabo']);
        $later = $this->stocked(0, ['name' => 'Tela']);
        $quote = $this->approvedOrder([[$back, 2], [$lost, 3], [$later, 1]]);
        $order = WorkOrder::find($quote->work_order_id);
        app(ReserveParts::class)->handle($order, $quote->id, $this->admin, true);
        app(StartRepair::class)->handle($order, $quote->id, $this->admin, true);        // consome Fonte e Cabo; Tela pendente
        app(ReceivePart::class)->handle($later, 4, 'Chegou', $this->admin);
        app(ReserveParts::class)->handle($order, $quote->id, $this->admin, true);        // Tela reservada, não consumida

        $this->cancel($quote, [['part_id' => $back->id, 'action' => 'return'], ['part_id' => $lost->id, 'action' => 'keep']])->assertOk();

        $this->assertSame([5, 2, 4], [$back->fresh()->on_hand, $lost->fresh()->on_hand, $later->fresh()->on_hand]);
        $this->assertSame([0, 0, 0], [$back->fresh()->reserved_quantity, $lost->fresh()->reserved_quantity, $later->fresh()->reserved_quantity]);
        $this->assertSame(['consumed', 'consumed', 'released'], PartReservation::orderBy('id')->pluck('status')->map->value->all());
        $event = $quote->workOrder->events()->where('type', 'canceled')->sole();
        $this->assertEquals([['part_id' => $later->id, 'quantity' => 1]], $event->metadata['released_reservations']);
        $this->assertEquals([['part_id' => $back->id, 'quantity' => 2]], $event->metadata['returned_parts']);
        $this->assertEquals([['part_id' => $lost->id, 'quantity' => 3]], $event->metadata['kept_parts']);
        $this->assertStockInvariant();
        $this->assertLedgerExplainsBalance();
    }

    public function test_a_part_consumed_in_two_steps_is_reversed_movement_by_movement(): void
    {
        $part = $this->stocked(3);
        $quote = $this->approvedOrder([[$part, 5]]);
        $order = WorkOrder::find($quote->work_order_id);
        app(ReserveParts::class)->handle($order, $quote->id, $this->admin, true);                 // 3 de 5
        app(StartRepair::class)->handle($order, $quote->id, $this->admin, true);                  // consumo 1: 3
        app(ReceivePart::class)->handle($part, 2, 'Chegou', $this->admin);
        app(ReserveParts::class)->handle($order, $quote->id, $this->admin, true);
        app(ConsumeReservations::class)->handle($order, $quote->id, $this->admin);                // consumo 2: 2
        $this->assertSame(0, $part->fresh()->on_hand);

        $this->cancel($quote, [['part_id' => $part->id, 'action' => 'return']])->assertOk();

        $this->assertSame(5, $part->fresh()->on_hand);
        $this->assertSame([3, 2], InventoryMovement::where('kind', 'return')->orderBy('reverses_movement_id')->pluck('quantity')->all());
        $this->assertSame(2, InventoryMovement::where('kind', 'return')->distinct('reverses_movement_id')->count('reverses_movement_id'));
        $this->assertLedgerExplainsBalance();
    }

    public function test_the_decisions_must_cover_exactly_the_consumed_parts_and_a_refusal_changes_nothing(): void
    {
        $a = $this->stocked(5);
        $b = $this->stocked(5);
        $other = $this->stocked(5);
        $quote = $this->started([[$a, 1], [$b, 1]]);
        $before = InventoryMovement::count();

        foreach ([
            'nenhuma' => [],
            'falta a outra' => [['part_id' => $a->id, 'action' => 'return']],
            'peça que não foi consumida' => [['part_id' => $a->id, 'action' => 'return'], ['part_id' => $b->id, 'action' => 'keep'], ['part_id' => $other->id, 'action' => 'return']],
        ] as $why => $parts) {
            $this->cancel($quote, $parts)->assertUnprocessable()->assertJsonValidationErrors('parts');
            $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status, $why);
        }
        $this->cancel($quote, [['part_id' => $a->id, 'action' => 'delete'], ['part_id' => $b->id, 'action' => 'keep']])->assertUnprocessable()->assertJsonValidationErrors('parts.0.action');
        $this->cancel($quote, [['part_id' => $a->id, 'action' => 'return'], ['part_id' => $a->id, 'action' => 'keep']])->assertUnprocessable()->assertJsonValidationErrors('parts.0.part_id');

        $this->assertSame($before, InventoryMovement::count());
        $this->assertSame([4, 4], [$a->fresh()->on_hand, $b->fresh()->on_hand]);
        $this->assertNull($quote->workOrder->fresh()->cancellation_reason);
    }

    public function test_an_order_without_consumed_parts_rejects_a_decision_for_a_part(): void
    {
        $part = $this->stocked(5);
        $quote = $this->approvedOrder([[$part, 1]]);

        $this->cancel($quote, [['part_id' => $part->id, 'action' => 'return']])->assertUnprocessable()->assertJsonValidationErrors('parts');
        $this->cancel($quote)->assertOk();                 // aprovada, nada consumido: como sempre
        $this->assertSame(0, InventoryMovement::where('kind', 'return')->count());
    }

    public function test_a_failure_in_the_middle_of_the_returns_rolls_everything_back(): void
    {
        $a = $this->stocked(5);
        $b = $this->stocked(5);
        $quote = $this->started([[$a, 1], [$b, 2]]);
        $movements = InventoryMovement::count();

        $calls = 0;
        InventoryMovement::creating(function ($m) use (&$calls) {
            if ($m->kind->value === 'return' && ++$calls === 2) {
                throw new RuntimeException('falha injetada no segundo estorno');
            }
        });
        try {
            app(CancelWorkOrder::class)->handle(WorkOrder::find($quote->work_order_id), 'Desistência', $this->admin, [$a->id => 'return', $b->id => 'return']);
            $this->fail('A falha injetada deveria ter interrompido o cancelamento.');
        } catch (RuntimeException) {
        }
        InventoryMovement::flushEventListeners();

        $this->assertSame(2, $calls);
        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);
        $this->assertSame([4, 3], [$a->fresh()->on_hand, $b->fresh()->on_hand], 'o primeiro estorno também foi desfeito');
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertDatabaseMissing('work_order_events', ['type' => 'canceled']);
        $this->assertLedgerExplainsBalance();
    }

    public function test_repeating_the_cancellation_does_not_return_twice_and_the_order_can_then_be_handed_back(): void
    {
        $part = $this->stocked(5);
        $quote = $this->started([[$part, 2]]);
        $decision = [['part_id' => $part->id, 'action' => 'return']];
        $this->cancel($quote, $decision)->assertOk();

        $this->cancel($quote, $decision)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->assertSame(1, InventoryMovement::where('kind', 'return')->count());
        $this->assertSame(5, $part->fresh()->on_hand);

        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/deliver")->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertSame(5, $part->fresh()->on_hand, 'devolver o equipamento não mexe no estoque');
        $this->assertLedgerExplainsBalance();
    }

    public function test_only_the_admin_cancels_and_the_detail_lists_what_was_consumed(): void
    {
        $a = $this->stocked(5, ['name' => 'Fonte']);
        $quote = $this->started([[$a, 2]]);

        $this->cancel($quote, [['part_id' => $a->id, 'action' => 'return']], User::factory()->technician()->create())->assertForbidden();
        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);

        $this->actingAs($this->admin)->getJson("/api/v1/work-orders/{$quote->work_order_id}")
            ->assertJsonPath('data.consumed_parts.0.name', 'Fonte')->assertJsonPath('data.consumed_parts.0.quantity', 2)->assertJsonCount(1, 'data.consumed_parts');
    }

    public function test_the_movements_screen_data_shows_the_reversal(): void
    {
        $part = $this->stocked(5);
        $quote = $this->started([[$part, 2]]);
        $this->cancel($quote, [['part_id' => $part->id, 'action' => 'return']])->assertOk();

        $this->actingAs($this->admin)->getJson("/api/v1/parts/{$part->id}/movements")->assertOk()
            ->assertJsonPath('data.0.kind', 'return')->assertJsonPath('data.0.kind_label', 'Estorno')->assertJsonPath('data.0.quantity', 2);
    }

    public function test_the_database_keeps_the_ledger_coherent_even_without_the_action(): void
    {
        $part = $this->stocked(5);
        $other = $this->stocked(5);
        $quote = $this->started([[$part, 3]]);
        $consumption = InventoryMovement::where('kind', 'consumption')->sole();
        $receipt = InventoryMovement::where('kind', 'receipt')->where('part_id', $part->id)->sole();
        $row = fn (array $o) => $o + ['part_id' => $part->id, 'kind' => 'return', 'quantity' => 1, 'reason' => 'x', 'created_by' => $this->admin->id, 'reverses_movement_id' => $consumption->id, 'reservation_id' => null, 'created_at' => now()];
        $fails = function (string $why, array $values, string $needle) {
            try {
                DB::transaction(fn () => DB::table('inventory_movements')->insert($values));
                $this->fail("deveria recusar: {$why}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $why);
            }
        };

        $fails('estorno sem consumo de origem', $row(['reverses_movement_id' => null]), 'a return must reverse a consumption');
        $fails('estorno de entrada', $row(['reverses_movement_id' => $receipt->id]), 'a return must reverse a consumption');
        $fails('estorno de outra peça', $row(['part_id' => $other->id]), 'a return must reverse a consumption of the same part');
        $fails('estorno acima do consumido', $row(['quantity' => 4]), 'would total 4 of 3');
        $fails('consumo com origem de estorno', $row(['kind' => 'consumption', 'reservation_id' => PartReservation::sole()->id + 100]), 'inventory_movements_reservation_check');
        $fails('tipo desconhecido', $row(['kind' => 'other']), 'inventory_movements_kind_check');

        DB::table('inventory_movements')->insert($row(['quantity' => 2]));
        DB::table('inventory_movements')->insert($row(['quantity' => 1]));      // 2 + 1 = 3: cabe
        $fails('soma dos estornos acima do consumido', $row(['quantity' => 1]), 'would total 4 of 3');

        foreach (['delete from inventory_movements', "update inventory_movements set reason = 'y'"] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} deveria ser recusado");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertNotNull($quote);
    }
}
