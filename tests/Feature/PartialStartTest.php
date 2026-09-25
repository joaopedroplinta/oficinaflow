<?php

namespace Tests\Feature;

use App\Actions\Inventory\ConsumeReservations;
use App\Actions\Inventory\ReceivePart;
use App\Actions\Inventory\ReserveParts;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Início parcial do reparo e consumo em etapas (PLANO §13.3). */
class PartialStartTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        InventoryMovement::flushEventListeners();

        parent::tearDown();
    }

    /** Peça com saldo dado por entrada (o livro-razão precisa explicar o saldo). */
    private function stocked(int $onHand, array $attrs = []): Part
    {
        $part = Part::factory()->create(['on_hand' => 0] + $attrs);
        if ($onHand > 0) {
            app(ReceivePart::class)->handle($part, $onHand, 'Estoque inicial', User::factory()->create());
        }

        return $part->fresh();
    }

    private function reserve(Quote $quote, bool $partial = true)
    {
        return $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id] + ($partial ? ['partial' => true] : []));
    }

    private function start(Quote $quote, array $extra = [], ?User $by = null)
    {
        return $this->actingAs($by ?? User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id] + $extra);
    }

    private function consume(Quote $quote, ?User $by = null)
    {
        return $this->actingAs($by ?? User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/consumptions", ['quote_id' => $quote->id]);
    }

    private function complete(Quote $quote)
    {
        return $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/complete-repair", ['work_performed' => 'Feito']);
    }

    /** on_hand == entradas − consumos, para toda peça (o livro-razão explica o saldo). */
    private function assertLedgerMatchesStock(): void
    {
        foreach (Part::all() as $part) {
            $ledger = InventoryMovement::where('part_id', $part->id)->get()->sum(fn ($m) => $m->kind->value === 'receipt' ? $m->quantity : -$m->quantity);
            $this->assertSame($ledger, $part->on_hand, "saldo de {$part->sku} não bate com o livro-razão");
        }
    }

    public function test_the_default_start_still_needs_everything_reserved(): void
    {
        $part = $this->stocked(3);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();

        $this->start($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION')->assertJsonPath('details.missing.0.quantity', 2);
        $this->start($quote, ['partial_start' => false])->assertConflict();

        $this->assertSame(WorkOrderStatus::Approved, $quote->workOrder->fresh()->status);
        $this->assertSame([3, 3], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
    }

    public function test_a_partial_start_consumes_only_what_is_reserved_and_leaves_the_rest_pending(): void
    {
        $short = $this->stocked(3, ['name' => 'Fonte']);
        $full = $this->stocked(4, ['name' => 'Cabo']);
        $none = $this->stocked(0, ['name' => 'Tela']);
        $quote = $this->approvedOrder([[$short, 5], [$full, 2], [$none, 1]]);
        $this->reserve($quote)->assertOk();

        $this->start($quote, ['partial_start' => true])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);
        $this->assertSame([0, 2, 0], [$short->fresh()->on_hand, $full->fresh()->on_hand, $none->fresh()->on_hand]);
        $this->assertSame([0, 0, 0], [$short->fresh()->reserved_quantity, $full->fresh()->reserved_quantity, $none->fresh()->reserved_quantity]);
        $this->assertSame(2, PartReservation::where('status', 'consumed')->count());
        $this->assertSame(2, InventoryMovement::where('kind', 'consumption')->count());
        $event = $quote->workOrder->events()->where('type', 'repair_started')->sole();
        $this->assertTrue($event->metadata['partial_start']);
        $this->assertEqualsCanonicalizing([['part_id' => $short->id, 'quantity' => 2], ['part_id' => $none->id, 'quantity' => 1]], $event->metadata['pending']);

        $detail = $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertOk();
        $this->assertEqualsCanonicalizing(['Fonte', 'Tela'], collect($detail->json('data.pending'))->pluck('name')->all());
        $this->getJson('/api/v1/work-orders?awaiting_parts=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.awaiting_parts', true);
        $this->assertStockInvariant();
        $this->assertLedgerMatchesStock();
    }

    public function test_the_flag_without_pending_parts_is_a_normal_start(): void
    {
        $part = $this->stocked(5);
        $quote = $this->approvedOrder([[$part, 2]]);
        $this->reserve($quote)->assertOk();

        $this->start($quote, ['partial_start' => true])->assertOk();

        $this->assertFalse($quote->workOrder->events()->where('type', 'repair_started')->sole()->metadata['partial_start']);
        $this->assertSame(3, $part->fresh()->on_hand);
    }

    public function test_a_partial_start_with_nothing_reserved_starts_without_consuming(): void
    {
        $part = $this->stocked(0);
        $quote = $this->approvedOrder([[$part, 2]]);

        $this->start($quote, ['partial_start' => true])->assertOk();

        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);
    }

    public function test_repeating_the_partial_start_does_not_consume_again(): void
    {
        $part = $this->stocked(3);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();
        $this->start($quote, ['partial_start' => true])->assertOk();

        $this->start($quote, ['partial_start' => true])->assertOk();

        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(1, $quote->workOrder->events()->where('type', 'repair_started')->count());
        $this->assertLedgerMatchesStock();
    }

    public function test_in_progress_one_reserves_what_was_missing_and_consumes_only_the_new_reservations(): void
    {
        $part = $this->stocked(3);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();
        $this->start($quote, ['partial_start' => true])->assertOk();

        // Sem estoque novo não há o que reservar; sem reserva nova não há o que consumir.
        $this->reserve($quote)->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->consume($quote)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());

        $this->actingAs(User::factory()->create())->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 2])->assertOk();
        $this->reserve($quote)->assertOk();
        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status, 'reservar não muda o estado');
        $this->assertSame([2, 2], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertJsonPath('data.to_consume.0.quantity', 2)->assertJsonCount(0, 'data.pending');

        $this->consume($quote)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', 2)->assertJsonPath('data.0.status', 'consumed');

        $this->assertSame([0, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame([3, 2], InventoryMovement::where('kind', 'consumption')->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(1, $quote->workOrder->events()->where('type', 'parts_consumed')->count());
        $this->assertStockInvariant();
        $this->assertLedgerMatchesStock();

        // Repetir não consome de novo.
        $this->consume($quote)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(2, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertSame(1, $quote->workOrder->events()->where('type', 'parts_consumed')->count());
        $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertJsonCount(0, 'data.to_consume');
    }

    public function test_the_repair_cannot_be_completed_with_parts_not_yet_consumed(): void
    {
        $part = $this->stocked(3, ['name' => 'Fonte']);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();
        $this->start($quote, ['partial_start' => true])->assertOk();

        $this->complete($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION')->assertJsonPath('details.pending.0.quantity', 2);

        // Reservada mas ainda não consumida também bloqueia.
        $this->actingAs(User::factory()->create())->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 2])->assertOk();
        $this->reserve($quote)->assertOk();
        $this->complete($quote)->assertConflict()->assertJsonPath('details.pending.0.quantity', 2);
        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);

        $this->consume($quote)->assertOk();
        $this->complete($quote)->assertOk()->assertJsonPath('data.status', 'ready_for_pickup');
    }

    public function test_consuming_is_refused_outside_in_progress_and_for_a_stale_quote(): void
    {
        $part = $this->stocked(3);
        foreach ([WorkOrderStatus::Approved, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::Delivered, WorkOrderStatus::AwaitingReturn] as $status) {
            $quote = $this->approvedOrder([[$part, 1]], $status);
            $this->consume($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        }

        // A tela mostrava outra versão que não a atual.
        $current = $this->approvedOrder([[$part, 1]], WorkOrderStatus::InProgress);
        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$current->work_order_id}/consumptions", ['quote_id' => $current->id + 1000])
            ->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
    }

    public function test_a_failure_in_the_middle_of_a_later_consumption_rolls_everything_back(): void
    {
        $a = $this->stocked(5);
        $b = $this->stocked(5);
        $quote = $this->approvedOrder([[$a, 2], [$b, 3]]);
        $admin = User::factory()->create();
        $this->start($quote, ['partial_start' => true])->assertOk();
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
        $movementsBefore = InventoryMovement::count();

        $calls = 0;
        InventoryMovement::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('falha injetada no meio do consumo');
            }
        });
        try {
            app(ConsumeReservations::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
            $this->fail('A falha injetada deveria ter interrompido o consumo.');
        } catch (RuntimeException) {
        }
        InventoryMovement::flushEventListeners();

        $this->assertSame(2, $calls);
        $this->assertSame([5, 5], [$a->fresh()->on_hand, $b->fresh()->on_hand]);
        $this->assertSame([2, 3], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity]);
        $this->assertSame(2, PartReservation::where('status', 'active')->count());
        $this->assertSame($movementsBefore, InventoryMovement::count());
        $this->assertDatabaseMissing('work_order_events', ['type' => 'parts_consumed']);

        // E dá para consumir normalmente depois.
        app(ConsumeReservations::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
        $this->assertSame([3, 2], [$a->fresh()->on_hand, $b->fresh()->on_hand]);
        $this->assertStockInvariant();
        $this->assertLedgerMatchesStock();
    }

    public function test_the_technician_consumes_but_only_the_admin_reserves(): void
    {
        $part = $this->stocked(3);
        $quote = $this->approvedOrder([[$part, 3]], WorkOrderStatus::InProgress, false);
        $tech = User::factory()->technician()->create();

        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertForbidden();
        $this->consume($quote, $tech)->assertOk();
        $this->postJson("/api/v1/work-orders/{$quote->work_order_id}/consumptions", [])->assertUnprocessable()->assertJsonValidationErrors('quote_id');
    }
}
