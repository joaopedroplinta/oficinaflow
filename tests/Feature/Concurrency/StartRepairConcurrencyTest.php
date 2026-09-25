<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Actions\Quotes\ReviseQuote;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;

/** Grupo 3 do PLANO §9 sob concorrência real: o consumo acontece uma vez, sem perder atualização. */
class StartRepairConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsApprovedOrders;

    /** Ordem aprovada com a reserva já feita (commitada). */
    private function reservedOrder(array $lines, User $admin): Quote
    {
        $quote = $this->approvedOrder($lines);
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        return $quote;
    }

    private function start(Quote $quote, User $actor): callable
    {
        return fn () => app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $actor);
    }

    public function test_two_simultaneous_starts_consume_only_once(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder([[$part, 2]], $admin);

        $result = $this->whileInFlight(
            $this->start($quote, $admin),
            'start', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result, 'a segunda chamada é uma repetição sem efeito');
        $this->assertSame(3, $part->fresh()->on_hand, 'consumiu uma vez só');
        $this->assertSame(0, $part->fresh()->reserved_quantity);
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'repair_started')->count());
        $this->assertStockInvariant();
    }

    public function test_two_orders_sharing_a_part_both_consume_without_losing_an_update(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $first = $this->reservedOrder([[$part, 2]], $admin);
        $second = $this->reservedOrder([[$part, 3]], $admin);

        $result = $this->whileInFlight(
            $this->start($first, $admin),
            'start', ['order_id' => $second->work_order_id, 'quote_id' => $second->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame([0, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity], '5 − 2 − 3: nenhuma baixa se perde');
        $this->assertSame(2, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(0, PartReservation::where('status', 'active')->count());
        $this->assertStockInvariant();
    }

    public function test_revising_a_version_whose_repair_is_starting_is_refused(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder([[$part, 2]], $admin);

        $result = $this->whileInFlight(
            $this->start($quote, $admin),
            'revise', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result, 'depois do início, orçamento e peças estão congelados');
        $this->assertSame(1, Quote::where('work_order_id', $quote->work_order_id)->count());
        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame('consumed', PartReservation::sole()->status->value);
        $this->assertStockInvariant();
    }

    public function test_starting_a_version_that_is_being_revised_is_refused_and_consumes_nothing(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $admin = User::factory()->create();
        $quote = $this->reservedOrder([[$part, 2]], $admin);

        $result = $this->whileInFlight(
            fn () => app(ReviseQuote::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'start', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'QUOTE_SUPERSEDED'], $result);
        $this->assertSame(5, $part->fresh()->on_hand, 'a revisão liberou a reserva e nada foi consumido');
        $this->assertSame(0, $part->fresh()->reserved_quantity);
        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(WorkOrderStatus::Diagnosing, $quote->workOrder->fresh()->status);
        $this->assertStockInvariant();
    }
}
