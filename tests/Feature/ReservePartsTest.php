<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

class ReservePartsTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private function reserve(Quote $seen, ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->postJson("/api/v1/work-orders/{$seen->work_order_id}/reservations", ['quote_id' => $seen->id]);
    }

    public function test_reserving_takes_every_part_and_leaves_the_physical_balance_and_the_order_state_alone(): void
    {
        $a = Part::factory()->create(['name' => 'Fonte', 'on_hand' => 5]);
        $b = Part::factory()->create(['name' => 'Cabo', 'on_hand' => 2]);
        $quote = $this->approvedOrder([[$a, 2], [$b, 2]]);
        $admin = User::factory()->create();

        $this->reserve($quote, $admin)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.status_label', 'Reservada')
            ->assertJsonPath('data.0.part.name', 'Fonte')->assertJsonPath('data.0.quantity', 2);

        $this->assertSame([5, 2], [$a->fresh()->on_hand, $b->fresh()->on_hand], 'reservar não baixa o saldo físico');
        $this->assertSame([2, 2], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity]);
        $this->assertSame(WorkOrderStatus::Approved, $quote->workOrder->fresh()->status);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'type' => 'parts_reserved', 'from_status' => 'approved', 'to_status' => 'approved']);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertStockInvariant();
    }

    public function test_repeating_returns_the_same_reservations_without_duplicating_anything(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 3]]);

        $first = $this->reserve($quote)->assertOk()->json('data.0.id');
        $this->reserve($quote)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);

        $this->assertDatabaseCount('part_reservations', 1);
        $this->assertSame(3, $part->fresh()->reserved_quantity);
        $this->assertDatabaseCount('work_order_events', 1);
        $this->assertStockInvariant();
    }

    public function test_all_or_nothing_when_one_part_is_short(): void
    {
        $plenty = Part::factory()->create(['sku' => 'CABO', 'name' => 'Cabo', 'on_hand' => 10]);
        $short = Part::factory()->create(['sku' => 'FONTE', 'name' => 'Fonte', 'on_hand' => 1]);
        $quote = $this->approvedOrder([[$plenty, 2], [$short, 2]]);

        $this->reserve($quote)->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.shortages.0.sku', 'FONTE')->assertJsonPath('details.shortages.0.needed', 2)->assertJsonPath('details.shortages.0.available', 1)
            ->assertJsonCount(1, 'details.shortages');

        // Nem a peça com estoque de sobra foi reservada.
        $this->assertDatabaseCount('part_reservations', 0);
        $this->assertSame([0, 0], [$plenty->fresh()->reserved_quantity, $short->fresh()->reserved_quantity]);
        $this->assertDatabaseCount('work_order_events', 0);
        $this->assertStockInvariant();
    }

    public function test_exactly_the_available_quantity_can_be_reserved_and_the_next_order_is_refused(): void
    {
        $part = Part::factory()->create(['on_hand' => 1]);
        $first = $this->approvedOrder([[$part, 1]]);
        $second = $this->approvedOrder([[$part, 1]]);

        $this->reserve($first)->assertOk();
        $this->reserve($second)->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('details.shortages.0.available', 0);

        $this->assertSame(1, $part->fresh()->reserved_quantity);
        $this->assertDatabaseCount('part_reservations', 1);
        $this->assertStockInvariant();
    }

    public function test_an_entry_of_stock_unblocks_the_reservation(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();

        $this->reserve($quote, $admin)->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 2])->assertOk();
        $this->reserve($quote, $admin)->assertOk();

        $this->assertSame([2, 2], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertStockInvariant();
    }

    public function test_a_labor_only_quote_reserves_nothing(): void
    {
        $quote = $this->approvedOrder([]);

        $this->reserve($quote)->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('part_reservations', 0);
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_a_stale_quote_id_answers_quote_superseded_and_reserves_nothing(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $old = $this->approvedOrder([[$part, 1]]);
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$old->work_order_id}/quote-revisions", ['quote_id' => $old->id])->assertCreated();

        $this->reserve($old, $admin)->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        $this->assertDatabaseCount('part_reservations', 0);
        $this->assertSame(0, $part->fresh()->reserved_quantity);
    }

    public function test_the_approval_is_required(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);

        foreach ([WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::InProgress, WorkOrderStatus::AwaitingReturn] as $status) {
            $quote = $this->approvedOrder([[$part, 1]], $status);
            $this->reserve($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        }

        // Ordem "aprovada" no status, mas a versão atual não tem decisão aprovada: nada de reservar só pelo status.
        // (Estado inalcançável em produção, por isso o trigger da decisão é suspenso só para montar o cenário.)
        $rejected = $this->approvedOrder([[$part, 1]]);
        DB::statement('ALTER TABLE quote_decisions DISABLE TRIGGER quote_decisions_immutable');
        DB::table('quote_decisions')->where('quote_id', $rejected->id)->update(['decision' => 'rejected']);
        DB::statement('ALTER TABLE quote_decisions ENABLE TRIGGER quote_decisions_immutable');
        $this->reserve($rejected)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('part_reservations', 0);
        $this->assertSame(0, $part->fresh()->reserved_quantity);
    }

    public function test_technician_cannot_reserve_guest_gets_401_and_quote_id_is_required(): void
    {
        $quote = $this->approvedOrder([[Part::factory()->create(), 1]]);

        $this->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertUnauthorized();
        $this->reserve($quote, User::factory()->technician()->create())->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", [])->assertUnprocessable()->assertJsonValidationErrors('quote_id');

        $this->assertDatabaseCount('part_reservations', 0);
    }

    public function test_the_detail_lists_reservations_and_what_is_still_pending(): void
    {
        $reserved = Part::factory()->create(['name' => 'Cabo', 'on_hand' => 5]);
        $short = Part::factory()->create(['name' => 'Fonte', 'on_hand' => 1]);
        $quote = $this->approvedOrder([[$reserved, 1]]);
        $tech = User::factory()->technician()->create();

        // Antes de reservar: a peça está pendente.
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$quote->work_order_id}")
            ->assertJsonPath('data.reservations', [])->assertJsonPath('data.pending.0.name', 'Cabo')->assertJsonPath('data.pending.0.available', 5);

        $this->reserve($quote)->assertOk();
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$quote->work_order_id}")
            ->assertJsonCount(1, 'data.reservations')->assertJsonPath('data.reservations.0.part.name', 'Cabo')->assertJsonPath('data.pending', []);

        // Peça sem estoque: continua pendente e a ordem continua "aprovada" (não vira outro estado).
        $other = $this->approvedOrder([[$short, 3]]);
        $this->reserve($other)->assertConflict();
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$other->work_order_id}")
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.pending.0.needed', 3)->assertJsonPath('data.pending.0.available', 1);
    }

    public function test_the_database_guards_reservations(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $this->reserve($quote)->assertOk();
        $item = $quote->items()->where('kind', 'part')->first();
        $reservation = PartReservation::sole();
        $base = ['quote_item_id' => $item->id, 'part_id' => $part->id, 'quantity' => 1, 'status' => 'active', 'reserved_by' => User::factory()->create()->id, 'reserved_at' => now()];

        foreach ([
            'segunda reserva do mesmo item' => fn () => DB::table('part_reservations')->insert($base),
            'quantidade zero' => fn () => DB::table('part_reservations')->insert(['quote_item_id' => $quote->items()->where('kind', 'service')->value('id'), 'quantity' => 0] + $base),
            'status inválido' => fn () => DB::table('part_reservations')->where('id', $reservation->id)->update(['status' => 'lost']),
            'liberada sem data' => fn () => DB::table('part_reservations')->where('id', $reservation->id)->update(['status' => 'released']),
            'ativa com data de liberação' => fn () => DB::table('part_reservations')->where('id', $reservation->id)->update(['released_at' => now()]),
            'mudar a quantidade' => fn () => DB::table('part_reservations')->where('id', $reservation->id)->update(['quantity' => 1]),
            'apagar' => fn () => DB::table('part_reservations')->where('id', $reservation->id)->delete(),
            'reservar mais que o saldo' => fn () => DB::table('parts')->where('id', $part->id)->update(['reserved_quantity' => 6]),
            'saldo abaixo do reservado' => fn () => DB::table('parts')->where('id', $part->id)->update(['on_hand' => 1]),
        ] as $what => $sql) {
            try {
                DB::transaction($sql);
                $this->fail("O banco aceitou: {$what}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Ativa pode terminar; depois de terminal, não muda mais.
        DB::table('part_reservations')->where('id', $reservation->id)->update(['status' => 'released', 'released_at' => now()]);
        try {
            DB::transaction(fn () => DB::table('part_reservations')->where('id', $reservation->id)->update(['status' => 'active', 'released_at' => null]));
            $this->fail('O banco reativou uma reserva liberada.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
