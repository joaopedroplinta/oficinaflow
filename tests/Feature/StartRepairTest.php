<?php

namespace Tests\Feature;

use App\Actions\Inventory\ReceivePart;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

class StartRepairTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    /** Peça com saldo dado por entrada (o livro-razão precisa explicar o saldo). */
    private function stocked(int $onHand, array $attrs = []): Part
    {
        $part = Part::factory()->create(['on_hand' => 0] + $attrs);
        app(ReceivePart::class)->handle($part, $onHand, 'Estoque inicial', User::factory()->create());

        return $part->fresh();
    }

    private function reserve($quote, User $by): void
    {
        $this->actingAs($by)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertOk();
    }

    private function start($quote, ?User $by = null)
    {
        return $this->actingAs($by ?? User::factory()->technician()->create())
            ->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id]);
    }

    /** on_hand == entradas − consumos, para toda peça. */
    private function assertLedgerExplainsBalance(): void
    {
        foreach (Part::all() as $part) {
            $net = (int) InventoryMovement::where('part_id', $part->id)->where('kind', 'receipt')->sum('quantity')
                - (int) InventoryMovement::where('part_id', $part->id)->where('kind', 'consumption')->sum('quantity');
            $this->assertSame($net, $part->on_hand, "o livro-razão de {$part->sku} não explica o saldo");
        }
    }

    public function test_starting_consumes_the_reserved_parts_and_moves_the_order_to_in_progress(): void
    {
        $a = $this->stocked(5, ['name' => 'Fonte']);
        $b = $this->stocked(2, ['name' => 'Cabo']);
        $quote = $this->approvedOrder([[$a, 2], [$b, 2]]);
        $admin = User::factory()->create();
        $this->reserve($quote, $admin);
        $tech = User::factory()->technician()->create(['name' => 'Tiago']);

        $this->start($quote, $tech)->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.status_label', 'Em reparo');

        $this->assertSame([3, 0], [$a->fresh()->on_hand, $b->fresh()->on_hand]);
        $this->assertSame([0, 0], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity], 'a reserva foi consumida');
        $this->assertSame(2, PartReservation::where('status', 'consumed')->whereNotNull('consumed_at')->count());
        $this->assertSame(0, PartReservation::where('status', 'active')->count());
        $this->assertSame(2, InventoryMovement::where('kind', 'consumption')->whereNotNull('reservation_id')->count());
        $order = $quote->workOrder->fresh();
        $this->assertSame(WorkOrderStatus::InProgress, $order->status);
        $this->assertNotNull($order->started_at);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'actor_id' => $tech->id, 'type' => 'repair_started', 'from_status' => 'approved', 'to_status' => 'in_progress']);
        $this->assertLedgerExplainsBalance();
        $this->assertStockInvariant();
    }

    public function test_each_consumption_is_tied_to_its_reservation_and_names_the_order(): void
    {
        $part = $this->stocked(4);
        $quote = $this->approvedOrder([[$part, 3]]);
        $this->reserve($quote, User::factory()->create());

        $this->start($quote)->assertOk();

        $movement = InventoryMovement::where('kind', 'consumption')->sole();
        $this->assertSame(PartReservation::sole()->id, $movement->reservation_id);
        $this->assertSame(3, $movement->quantity);
        $this->assertStringContainsString('Consumo na ordem #', $movement->reason);
    }

    public function test_repeating_does_not_consume_twice(): void
    {
        $part = $this->stocked(5);
        $quote = $this->approvedOrder([[$part, 2]]);
        $this->reserve($quote, User::factory()->create());

        $this->start($quote)->assertOk();
        $this->start($quote)->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertDatabaseCount('work_order_events', 2); // reserva + início (o segundo início não gera evento)
        $this->assertLedgerExplainsBalance();
    }

    public function test_a_labor_only_quote_starts_without_reservations(): void
    {
        $quote = $this->approvedOrder([]);

        $this->start($quote)->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_missing_reservations_block_the_start_and_consume_nothing_not_even_the_reserved_part(): void
    {
        $reserved = $this->stocked(5, ['name' => 'Cabo']);
        $lacking = $this->stocked(5, ['name' => 'Fonte']);
        $quote = $this->approvedOrder([[$reserved, 2], [$lacking, 1]]);
        $this->reserve($quote, User::factory()->create());
        // Estado que a produção não alcança (reserva parcial): a reserva da Fonte é liberada por fora só para montar o cenário.
        $lackingItem = $quote->items()->where('part_id', $lacking->id)->first();
        \DB::statement('ALTER TABLE part_reservations DISABLE TRIGGER part_reservations_guard');
        \DB::table('part_reservations')->where('quote_item_id', $lackingItem->id)->update(['status' => 'released', 'released_at' => now()]);
        \DB::statement('ALTER TABLE part_reservations ENABLE TRIGGER part_reservations_guard');

        $this->start($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION')
            ->assertJsonCount(1, 'details.missing')->assertJsonPath('details.missing.0.name', 'Fonte');

        // Tudo ou nada: o Cabo, que estava reservado, também não foi consumido.
        $this->assertSame(WorkOrderStatus::Approved, $quote->workOrder->fresh()->status);
        $this->assertSame([5, 5], [$reserved->fresh()->on_hand, $lacking->fresh()->on_hand]);
        $this->assertSame(2, $reserved->fresh()->reserved_quantity);
        $this->assertSame('active', PartReservation::where('part_id', $reserved->id)->sole()->status->value);
        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertDatabaseMissing('work_order_events', ['type' => 'repair_started']);
    }

    public function test_the_order_must_be_approved_with_an_approved_current_quote(): void
    {
        $part = $this->stocked(5);

        foreach ([WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::AwaitingReturn, WorkOrderStatus::Canceled] as $status) {
            $quote = $this->approvedOrder([[$part, 1]], $status);
            $this->start($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
            $this->assertSame($status, $quote->workOrder->fresh()->status);
        }
        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());

        // Status "aprovada", mas a versão atual não tem decisão aprovada: só o status não basta.
        $quote = $this->approvedOrder([[$part, 1]]);
        \DB::statement('ALTER TABLE quote_decisions DISABLE TRIGGER quote_decisions_immutable');
        \DB::table('quote_decisions')->where('quote_id', $quote->id)->update(['decision' => 'rejected']);
        \DB::statement('ALTER TABLE quote_decisions ENABLE TRIGGER quote_decisions_immutable');
        $this->start($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    public function test_a_stale_quote_id_answers_quote_superseded(): void
    {
        $part = $this->stocked(5);
        $old = $this->approvedOrder([[$part, 1]]);
        $admin = User::factory()->create();
        $this->reserve($old, $admin);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$old->work_order_id}/quote-revisions", ['quote_id' => $old->id])->assertCreated();

        $this->start($old, $admin)->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertStockInvariant();
    }

    public function test_technician_and_admin_may_start_guest_gets_401_and_quote_id_is_required(): void
    {
        $part = $this->stocked(10);
        $first = $this->approvedOrder([[$part, 1]]);
        $second = $this->approvedOrder([[$part, 1]]);
        $admin = User::factory()->create();

        // Visitante primeiro: o actingAs de qualquer chamada anterior valeria para o resto do teste.
        $this->postJson("/api/v1/work-orders/{$first->work_order_id}/start-repair", ['quote_id' => $first->id])->assertUnauthorized();
        $this->reserve($first, $admin);
        $this->reserve($second, $admin);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$first->work_order_id}/start-repair", [])->assertUnprocessable()->assertJsonValidationErrors('quote_id');
        $this->start($first, User::factory()->technician()->create())->assertOk();
        $this->start($second, $admin)->assertOk();
    }

    public function test_after_the_start_quote_and_parts_are_frozen(): void
    {
        $part = $this->stocked(5);
        $quote = $this->approvedOrder([[$part, 1]]);
        $admin = User::factory()->create();
        $this->reserve($quote, $admin);
        $this->start($quote, $admin)->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/quote-revisions", ['quote_id' => $quote->id])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertConflict();
        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => []])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", ['decision' => 'rejected', 'customer_name' => 'x', 'channel' => 'phone'])->assertConflict();

        $this->assertSame(4, $part->fresh()->on_hand);
        $this->assertSame('consumed', PartReservation::sole()->status->value);
    }

    public function test_the_detail_shows_the_consumed_reservations(): void
    {
        $part = $this->stocked(5, ['name' => 'Fonte']);
        $quote = $this->approvedOrder([[$part, 2]]);
        $this->reserve($quote, User::factory()->create());
        $this->start($quote)->assertOk();

        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$quote->work_order_id}")
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.pending', [])
            ->assertJsonPath('data.reservations.0.status_label', 'Consumida')->assertJsonPath('data.reservations.0.part.name', 'Fonte');
    }
}
