<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Inventory\ReserveParts;
use App\Actions\Quotes\ReviseQuote;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;

/** Grupo 1 do PLANO §9: reservas disputando o mesmo estoque, com duas conexões reais ao PostgreSQL. */
class PartReservationConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsApprovedOrders;

    public function test_two_orders_fighting_for_the_last_unit_only_one_wins(): void
    {
        $part = Part::factory()->create(['on_hand' => 1]);
        $first = $this->approvedOrder([[$part, 1]]);
        $second = $this->approvedOrder([[$part, 1]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($first->work_order_id), $first->id, $admin),
            'reserve', ['order_id' => $second->work_order_id, 'quote_id' => $second->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INSUFFICIENT_STOCK'], $result);
        $this->assertSame(1, PartReservation::count());
        $this->assertSame(1, $part->fresh()->reserved_quantity);
        $this->assertSame(1, $part->fresh()->on_hand);
        $this->assertStockInvariant();
    }

    public function test_an_order_that_needs_two_when_one_is_left_reserves_nothing_at_all(): void
    {
        // A precisa de 2 de X; sobra 1 quando ela termina. B precisa de 1 de X e 1 de Y: X falta, então Y não pode ser reservada.
        $x = Part::factory()->create(['sku' => 'X', 'on_hand' => 2]);
        $y = Part::factory()->create(['sku' => 'Y', 'on_hand' => 5]);
        $a = $this->approvedOrder([[$x, 2]]);
        $b = $this->approvedOrder([[$x, 1], [$y, 1]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($a->work_order_id), $a->id, $admin),
            'reserve', ['order_id' => $b->work_order_id, 'quote_id' => $b->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INSUFFICIENT_STOCK'], $result);
        $this->assertSame(0, PartReservation::whereHas('quoteItem', fn ($q) => $q->where('quote_id', $b->id))->count(), 'sem reserva parcial');
        $this->assertSame([2, 0], [$x->fresh()->reserved_quantity, $y->fresh()->reserved_quantity]);
        $this->assertStockInvariant();
    }

    public function test_two_partial_reservations_fighting_for_the_last_unit_only_one_gets_it(): void
    {
        $part = Part::factory()->create(['on_hand' => 1]);
        $first = $this->approvedOrder([[$part, 3]]);
        $second = $this->approvedOrder([[$part, 3]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($first->work_order_id), $first->id, $admin, true),
            'reserve', ['order_id' => $second->work_order_id, 'quote_id' => $second->id, 'actor_id' => $admin->id, 'partial' => true],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INSUFFICIENT_STOCK'], $result, 'a segunda não encontra nada para reservar');
        $this->assertSame(1, PartReservation::count());
        $this->assertSame(1, PartReservation::sole()->quantity);
        $this->assertSame([1, 1], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertStockInvariant();
    }

    public function test_two_partial_reservations_split_the_stock_without_overselling(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $first = $this->approvedOrder([[$part, 3]]);
        $second = $this->approvedOrder([[$part, 3]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($first->work_order_id), $first->id, $admin, true),
            'reserve', ['order_id' => $second->work_order_id, 'quote_id' => $second->id, 'actor_id' => $admin->id, 'partial' => true],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $byOrder = PartReservation::with('quoteItem.quote')->get()->mapWithKeys(fn ($r) => [$r->quoteItem->quote->work_order_id => $r->quantity])->all();
        $this->assertSame([$first->work_order_id => 3, $second->work_order_id => 2], $byOrder, 'quem chegou primeiro leva tudo o que pediu; o outro fica com o que sobrou');
        $this->assertSame([5, 5], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertStockInvariant();
    }

    public function test_two_simultaneous_partial_requests_for_the_same_order_do_not_reserve_more_than_the_item_needs(): void
    {
        $part = Part::factory()->create(['on_hand' => 10]);
        $quote = $this->approvedOrder([[$part, 4]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin, true),
            'reserve', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id, 'partial' => true],
        );

        $this->assertSame(['result' => 'ok'], $result, 'a segunda vê tudo reservado e devolve o que existe');
        $this->assertSame(4, PartReservation::sum('quantity'), 'a soma das reservas do item nunca passa da quantidade dele');
        $this->assertSame(4, $part->fresh()->reserved_quantity);
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'parts_reserved')->count());
        $this->assertStockInvariant();
    }

    public function test_two_simultaneous_requests_for_the_same_order_reserve_once(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'reserve', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result, 'a segunda requisição devolve as reservas existentes');
        $this->assertSame(1, PartReservation::count());
        $this->assertSame(2, $part->fresh()->reserved_quantity);
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'parts_reserved')->count());
        $this->assertStockInvariant();
    }

    public function test_reserving_a_version_that_is_being_revised_is_refused(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReviseQuote::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'reserve', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'QUOTE_SUPERSEDED'], $result);
        $this->assertSame(0, PartReservation::count());
        $this->assertSame(0, $part->fresh()->reserved_quantity);
        $this->assertStockInvariant();
    }

    public function test_revising_a_version_that_is_being_reserved_releases_what_was_reserved(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin),
            'revise', ['order_id' => $quote->work_order_id, 'quote_id' => $quote->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame(1, PartReservation::where('status', 'released')->count());
        $this->assertSame(0, $part->fresh()->reserved_quantity, 'a reserva feita antes da revisão foi liberada');
        $this->assertSame(5, $part->fresh()->on_hand);
        $this->assertStockInvariant();
    }
}
