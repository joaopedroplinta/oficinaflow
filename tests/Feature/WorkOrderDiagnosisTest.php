<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderDiagnosisTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_technician_start_the_diagnosis(): void
    {
        foreach ([User::factory()->create(), User::factory()->technician()->create()] as $user) {
            $order = WorkOrder::factory()->create();

            $this->actingAs($user)->postJson("/api/v1/work-orders/{$order->id}/start-diagnosis")
                ->assertOk()->assertJsonPath('data.status', 'diagnosing')->assertJsonPath('data.status_label', 'Em diagnóstico');

            $this->assertDatabaseHas('work_order_events', [
                'work_order_id' => $order->id, 'actor_id' => $user->id, 'type' => 'diagnosis_started',
                'from_status' => 'received', 'to_status' => 'diagnosing',
            ]);
        }
    }

    public function test_starting_twice_is_a_conflict_and_records_nothing_new(): void
    {
        $order = WorkOrder::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/work-orders/{$order->id}/start-diagnosis")->assertOk();
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$order->id}/start-diagnosis")
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('work_order_events', 1);
    }

    public function test_cannot_start_the_diagnosis_from_another_state(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Delivered)->create();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$order->id}/start-diagnosis")
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertSame(WorkOrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_guest_gets_401(): void
    {
        $order = WorkOrder::factory()->create();

        $this->postJson("/api/v1/work-orders/{$order->id}/start-diagnosis")->assertUnauthorized();
        $this->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", [])->assertUnauthorized();
    }

    public function test_records_the_diagnosis_and_the_technician(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $tech = User::factory()->technician()->create();

        $this->actingAs($tech)->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", ['diagnosis' => 'Placa-mãe queimada', 'assigned_technician_id' => $tech->id])
            ->assertOk()
            ->assertJsonPath('data.diagnosis', 'Placa-mãe queimada')
            ->assertJsonPath('data.assigned_technician.id', $tech->id)
            ->assertJsonPath('data.status', 'diagnosing');

        $this->assertDatabaseHas('work_orders', ['id' => $order->id, 'diagnosis' => 'Placa-mãe queimada', 'assigned_technician_id' => $tech->id]);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'actor_id' => $tech->id, 'type' => 'diagnosis_recorded', 'to_status' => 'diagnosing']);
    }

    public function test_editing_the_diagnosis_keeps_the_technician_when_none_is_sent(): void
    {
        $tech = User::factory()->technician()->create();
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create(['assigned_technician_id' => $tech->id]);

        $this->actingAs(User::factory()->create())->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", ['diagnosis' => 'Fonte com defeito'])->assertOk();

        $this->assertSame($tech->id, $order->fresh()->assigned_technician_id);
    }

    public function test_cannot_record_a_diagnosis_outside_the_diagnosing_state(): void
    {
        $order = WorkOrder::factory()->create();

        $this->actingAs(User::factory()->create())->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", ['diagnosis' => 'x'])
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertNull($order->fresh()->diagnosis);
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_diagnosis_text_is_required(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();

        $this->actingAs(User::factory()->create())->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", [])
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');
    }

    public function test_responsible_must_be_an_active_technician(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $admin = User::factory()->create();

        foreach ([$admin->id, User::factory()->technician()->inactive()->create()->id, 999999] as $id) {
            $this->actingAs($admin)->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", ['diagnosis' => 'x', 'assigned_technician_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('assigned_technician_id');
        }

        $this->assertNull($order->fresh()->assigned_technician_id);
    }

    public function test_status_cannot_be_forced_through_the_diagnosis_endpoint(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/work-orders/{$order->id}/diagnosis", ['diagnosis' => 'x', 'status' => 'approved'])->assertOk();

        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status);
    }

    public function test_lists_only_active_technicians(): void
    {
        $tech = User::factory()->technician()->create(['name' => 'Tiago']);
        User::factory()->technician()->inactive()->create();
        User::factory()->create();

        $this->actingAs(User::factory()->create())->getJson('/api/v1/technicians')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tech->id);
    }
}
