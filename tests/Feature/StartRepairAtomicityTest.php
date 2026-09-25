<?php

namespace Tests\Feature;

use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Grupo 3 do PLANO §9: iniciar o reparo é atômico. */
class StartRepairAtomicityTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // A falha injetada é um listener estático: não pode vazar para os outros testes do processo.
        InventoryMovement::flushEventListeners();

        parent::tearDown();
    }

    public function test_a_failure_in_the_middle_of_the_consumption_rolls_everything_back(): void
    {
        $a = Part::factory()->create(['on_hand' => 5]);
        $b = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$a, 2], [$b, 3]]);
        $admin = User::factory()->create();
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        // Derruba o consumo da SEGUNDA peça: a primeira já foi baixada, movimentada e marcada como consumida.
        $calls = 0;
        InventoryMovement::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('falha injetada no meio do consumo');
            }
        });

        try {
            app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
            $this->fail('A falha injetada deveria ter interrompido o início do reparo.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha injetada no meio do consumo', $e->getMessage());
        }

        $this->assertSame(2, $calls, 'a falha aconteceu depois de a primeira peça ter sido processada');
        $order = WorkOrder::find($quote->work_order_id);
        $this->assertSame(WorkOrderStatus::Approved, $order->status);
        $this->assertNull($order->started_at);
        $this->assertSame([5, 5], [$a->fresh()->on_hand, $b->fresh()->on_hand], 'nenhum saldo físico mudou');
        $this->assertSame([2, 3], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity], 'as reservas continuam');
        $this->assertSame(2, PartReservation::where('status', 'active')->count());
        $this->assertSame(0, PartReservation::where('status', 'consumed')->count());
        $this->assertSame(0, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertDatabaseMissing('work_order_events', ['type' => 'repair_started']);
        $this->assertStockInvariant();
    }

    public function test_after_a_failed_start_the_repair_can_be_started_again_normally(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();
        app(ReserveParts::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);

        InventoryMovement::creating(fn () => throw new RuntimeException('falha'));
        try {
            app(StartRepair::class)->handle(WorkOrder::find($quote->work_order_id), $quote->id, $admin);
        } catch (RuntimeException) {
        }
        InventoryMovement::flushEventListeners();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id])->assertOk();

        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertStockInvariant();
    }
}
