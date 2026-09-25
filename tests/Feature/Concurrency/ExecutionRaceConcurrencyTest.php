<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Inventory\ReceivePart;
use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Actions\WorkOrders\CompleteRepair;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;

/** Início do reparo contra conclusão e contra entrada de estoque, com duas conexões reais. */
class ExecutionRaceConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsApprovedOrders;

    private function stocked(int $onHand): Part
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        app(ReceivePart::class)->handle($part, $onHand, 'Estoque inicial', User::factory()->create());

        return $part->fresh();
    }

    public function test_completing_an_order_whose_repair_is_starting_waits_and_then_completes(): void
    {
        $part = $this->stocked(5);
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$part, 2]]);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        $result = $this->whileInFlight(
            fn () => app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'complete', ['order_id' => $quote->work_order_id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result, 'a conclusão esperou o lock e viu a ordem já em reparo');
        $this->assertSame(WorkOrderStatus::ReadyForPickup, $quote->workOrder->fresh()->status);
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count(), 'consumiu uma vez');
        $this->assertSame(['repair_started', 'repair_completed'], DB::table('work_order_events')->whereIn('type', ['repair_started', 'repair_completed'])->orderBy('id')->pluck('type')->all());
        $this->assertStockInvariant();
    }

    public function test_starting_a_repair_that_is_being_completed_is_refused_and_consumes_nothing_more(): void
    {
        $part = $this->stocked(5);
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$part, 2]]);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
        app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        $result = $this->whileInFlight(
            fn () => app(CompleteRepair::class)->handle(WorkOrder::find($quote->work_order_id), 'Serviço feito', $admin),
            'start', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        // Ao esperar o lock, o início vê a ordem já pronta para retirada: não é mais repetição, é transição inválida.
        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'repair_started')->count());
        $this->assertStockInvariant();
    }

    public function test_starting_a_repair_while_stock_is_being_received_for_the_same_part_loses_no_update(): void
    {
        $part = $this->stocked(5);
        $admin = User::factory()->create();
        $quote = $this->approvedOrder([[$part, 2]]);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        // A entrada de +3 está em andamento (peça travada); o início do reparo precisa esperar essa mesma peça.
        $result = $this->whileInFlight(
            fn () => app(ReceivePart::class)->handle($part, 3, 'Compra', $admin),
            'start', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $fresh = $part->fresh();
        $this->assertSame([6, 0], [$fresh->on_hand, $fresh->reserved_quantity], '5 + 3 − 2: nem a entrada nem o consumo se perdem');
        $this->assertSame(
            (int) InventoryMovement::where('part_id', $part->id)->where('kind', 'receipt')->sum('quantity') - (int) InventoryMovement::where('part_id', $part->id)->where('kind', 'consumption')->sum('quantity'),
            $fresh->on_hand,
            'o livro-razão explica o saldo',
        );
        $this->assertSame(1, PartReservation::where('status', 'consumed')->count());
        $this->assertStockInvariant();
    }
}
