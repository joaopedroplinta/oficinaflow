<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function order(WorkOrderStatus $status): WorkOrder
    {
        return WorkOrder::factory()->status($status)->create(['diagnosis' => 'x']);
    }

    public function test_the_technician_completes_the_repair_with_the_work_performed(): void
    {
        $order = $this->order(WorkOrderStatus::InProgress);
        $tech = User::factory()->technician()->create();

        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => '  Troca da fonte e teste de carga.  '])
            ->assertOk()->assertJsonPath('data.status', 'ready_for_pickup')->assertJsonPath('data.status_label', 'Pronta para retirada')
            ->assertJsonPath('data.work_performed', 'Troca da fonte e teste de carga.');

        $fresh = $order->fresh();
        $this->assertSame(WorkOrderStatus::ReadyForPickup, $fresh->status);
        $this->assertNotNull($fresh->ready_at);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'actor_id' => $tech->id, 'type' => 'repair_completed', 'from_status' => 'in_progress', 'to_status' => 'ready_for_pickup']);
    }

    public function test_the_admin_delivers_and_the_technician_cannot(): void
    {
        $order = $this->order(WorkOrderStatus::ReadyForPickup);
        $admin = User::factory()->create();

        $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertForbidden();
        $this->assertSame(WorkOrderStatus::ReadyForPickup, $order->fresh()->status);

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.status_label', 'Entregue');

        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'actor_id' => $admin->id, 'type' => 'delivered', 'from_status' => 'ready_for_pickup', 'to_status' => 'delivered']);
    }

    public function test_the_admin_may_also_complete(): void
    {
        $order = $this->order(WorkOrderStatus::InProgress);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => 'Limpeza e troca de pasta térmica'])->assertOk();
    }

    public function test_the_description_of_the_work_is_required(): void
    {
        $order = $this->order(WorkOrderStatus::InProgress);
        $tech = User::factory()->technician()->create();

        foreach ([[], ['work_performed' => ''], ['work_performed' => '   '], ['work_performed' => 'ok'], ['work_performed' => str_repeat('x', 2001)]] as $body) {
            $this->actingAs($tech)->postJson("/api/v1/work-orders/{$order->id}/complete-repair", $body)->assertUnprocessable()->assertJsonValidationErrors('work_performed');
        }

        $this->assertSame(WorkOrderStatus::InProgress, $order->fresh()->status);
        $this->assertNull($order->fresh()->work_performed);
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_transitions_outside_their_source_state_are_conflicts(): void
    {
        $tech = User::factory()->technician()->create();
        $admin = User::factory()->create();

        foreach ([WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::Delivered, WorkOrderStatus::AwaitingReturn, WorkOrderStatus::Canceled] as $status) {
            $order = $this->order($status);
            $this->actingAs($tech)->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => 'Serviço qualquer'])
                ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
            $this->assertSame($status, $order->fresh()->status);
        }
        foreach ([WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved, WorkOrderStatus::InProgress, WorkOrderStatus::Delivered, WorkOrderStatus::Canceled] as $status) {
            $order = $this->order($status);
            $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
            $this->assertSame($status, $order->fresh()->status);
        }
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_repeating_a_completed_or_delivered_step_is_a_conflict_and_records_nothing_new(): void
    {
        $order = $this->order(WorkOrderStatus::InProgress);
        $admin = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => 'Serviço feito'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => 'Serviço feito'])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertConflict();

        $this->assertDatabaseCount('work_order_events', 2);
    }

    public function test_guest_gets_401(): void
    {
        $order = $this->order(WorkOrderStatus::InProgress);

        $this->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => 'Serviço feito'])->assertUnauthorized();
        $this->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertUnauthorized();
    }

    public function test_the_detail_and_the_timeline_show_the_execution(): void
    {
        $order = $this->order(WorkOrderStatus::InProgress);
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/complete-repair", ['work_performed' => 'Fonte trocada'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertOk();

        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$order->id}")
            ->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.work_performed', 'Fonte trocada')
            ->assertJsonPath('data.ready_at', fn ($v) => $v !== null)->assertJsonPath('data.delivered_at', fn ($v) => $v !== null);
        $this->getJson("/api/v1/work-orders/{$order->id}/events")
            ->assertJsonPath('data.0.type_label', 'Reparo concluído')->assertJsonPath('data.1.type_label', 'Equipamento entregue');
    }

    public function test_the_database_keeps_execution_states_consistent(): void
    {
        $inProgress = $this->order(WorkOrderStatus::InProgress);
        $received = $this->order(WorkOrderStatus::Received);

        foreach ([
            'pronta sem descrição' => fn () => DB::table('work_orders')->where('id', $inProgress->id)->update(['status' => 'ready_for_pickup', 'ready_at' => now()]),
            'pronta com descrição em branco' => fn () => DB::table('work_orders')->where('id', $inProgress->id)->update(['status' => 'ready_for_pickup', 'ready_at' => now(), 'work_performed' => '   ']),
            'pronta sem data' => fn () => DB::table('work_orders')->where('id', $inProgress->id)->update(['status' => 'ready_for_pickup', 'work_performed' => 'x']),
            'entregue sem data de entrega' => fn () => DB::table('work_orders')->where('id', $inProgress->id)->update(['status' => 'delivered', 'ready_at' => now(), 'work_performed' => 'x']),
            'em reparo sem data de início' => fn () => DB::table('work_orders')->where('id', $received->id)->update(['status' => 'in_progress']),
        ] as $what => $sql) {
            try {
                DB::transaction($sql);
                $this->fail("O banco aceitou: {$what}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_database_keeps_the_execution_dates_in_order(): void
    {
        $delivered = $this->order(WorkOrderStatus::Delivered);
        $now = now();

        foreach ([
            'iniciada antes de ser recebida' => ['started_at' => $now->copy()->subDays(2), 'received_at' => $now],
            'concluída antes de iniciada' => ['ready_at' => $now->copy()->subDays(1)],
            'entregue antes de concluída' => ['delivered_at' => $now->copy()->subDays(1)],
        ] as $what => $values) {
            $values += ['received_at' => $now->copy()->subHours(6), 'started_at' => $now->copy()->subHours(3), 'ready_at' => $now->copy()->subHours(1)];
            try {
                DB::transaction(fn () => DB::table('work_orders')->where('id', $delivered->id)->update($values));
                $this->fail("O banco aceitou: {$what}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Datas iguais e em ordem são válidas.
        DB::table('work_orders')->where('id', $delivered->id)->update(['received_at' => $now, 'started_at' => $now, 'ready_at' => $now, 'delivered_at' => $now]);
        $this->assertSame(WorkOrderStatus::Delivered, $delivered->fresh()->status);
    }
}
