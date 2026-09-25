<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Inventory\ConsumeReservations;
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
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;

/** Grupo 4 do PLANO §9 sob concorrência real: cancelar e iniciar o reparo não podem ambos valer. */
class CancellationConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsApprovedOrders;

    private function reservedOrder(Part $part, int $qty, User $admin): Quote
    {
        $quote = $this->approvedOrder([[$part, $qty]]);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        return $quote;
    }

    public function test_starting_the_repair_of_an_order_that_is_being_cancelled_is_refused_and_consumes_nothing(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder($part, 2, $admin);

        $result = $this->whileInFlight(
            fn () => app(CancelWorkOrder::class)->handle(WorkOrder::find($quote->work_order_id), 'Desistência', $admin),
            'start', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(WorkOrderStatus::AwaitingReturn, $quote->workOrder->fresh()->status);
        $this->assertSame([5, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity], 'a reserva foi liberada e nada foi consumido');
        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(1, PartReservation::where('status', 'released')->count());
        $this->assertStockInvariant();
    }

    public function test_cancelling_while_the_repair_is_starting_waits_and_then_returns_the_consumed_part(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder($part, 2, $admin);

        $result = $this->whileInFlight(
            fn () => app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'parts' => [$part->id => 'return']],
        );

        $this->assertSame(['result' => 'ok'], $result, 'o cancelamento esperou o início e viu a peça já consumida');
        $this->assertSame(WorkOrderStatus::AwaitingReturn, $quote->workOrder->fresh()->status);
        $this->assertSame([5, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame('consumed', PartReservation::sole()->status->value, 'o consumo continua no histórico');
        $this->assertSame(['consumption', 'return'], InventoryMovement::orderBy('id')->pluck('kind')->map->value->all());
        $this->assertStockInvariant();
    }

    public function test_cancelling_while_the_repair_is_starting_without_a_decision_for_the_consumed_part_is_refused(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder($part, 2, $admin);

        $result = $this->whileInFlight(
            fn () => app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'validation', 'fields' => ['parts']], $result);
        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);
        $this->assertSame([3, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame(1, InventoryMovement::count());
        $this->assertNull($quote->workOrder->fresh()->cancellation_reason);
        $this->assertStockInvariant();
    }

    public function test_two_simultaneous_cancellations_release_once(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder($part, 2, $admin);

        $result = $this->whileInFlight(
            fn () => app(CancelWorkOrder::class)->handle(WorkOrder::find($quote->work_order_id), 'Desistência', $admin),
            'cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(0, $part->fresh()->reserved_quantity, 'liberou uma vez só: não ficou negativo');
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'canceled')->count());
        $this->assertStockInvariant();
    }

    public function test_two_simultaneous_cancellations_in_progress_return_the_part_once(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder($part, 2, $admin);
        app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
        $decisions = [$part->id => 'return'];

        $result = $this->whileInFlight(
            fn () => app(CancelWorkOrder::class)->handle(WorkOrder::find($quote->work_order_id), 'Primeiro', $admin, $decisions),
            'cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'parts' => $decisions],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(1, InventoryMovement::where('kind', 'return')->count());
        $this->assertSame(5, $part->fresh()->on_hand, 'voltou uma vez só');
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'canceled')->count());
        $this->assertStockInvariant();
    }

    public function test_cancelling_while_a_consumption_is_in_flight_waits_and_returns_it_too(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$part, 5]]);
        $order = WorkOrder::find($quote->work_order_id);
        app(ReserveParts::class)->handle($order, $quote->id, $admin, true);
        app(StartRepair::class)->handle($order, $quote->id, $admin, true);                        // consumiu 3
        $part->forceFill(['on_hand' => 2])->save();
        app(ReserveParts::class)->handle($order, $quote->id, $admin, true);                       // reservou 2, ainda não consumidas

        $result = $this->whileInFlight(
            fn () => app(ConsumeReservations::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'cancel', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'parts' => [$part->id => 'return']],
        );

        $this->assertSame(['result' => 'ok'], $result, 'o cancelamento esperou o consumo e estornou os dois');
        $this->assertSame([5, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame([3, 2], InventoryMovement::where('kind', 'return')->orderBy('reverses_movement_id')->pluck('quantity')->all());
        $this->assertStockInvariant();
    }
}
