<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_mine_and_summary_use_the_authenticated_technician(): void
    {
        $technician = User::factory()->technician()->create();
        $mine = WorkOrder::factory()->status(WorkOrderStatus::ReadyForPickup)->create(['assigned_technician_id' => $technician->id]);
        WorkOrder::factory()->status(WorkOrderStatus::ReadyForPickup)->create();
        WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create(['assigned_technician_id' => $technician->id]);

        $this->actingAs($technician)->getJson('/api/v1/work-orders?mine=1&include_summary=1&status=ready_for_pickup')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.assigned_technician.id', $technician->id)
            ->assertJsonPath('summary.ready_for_pickup', 1);
        $this->getJson('/api/v1/work-orders?include_summary=1&status=received')
            ->assertJsonCount(0, 'data')->assertJsonPath('summary.ready_for_pickup', 2);
    }

    public function test_date_filters_include_the_entire_brazilian_day(): void
    {
        WorkOrder::factory()->create(['received_at' => '2026-09-25 02:59:59+00']);
        $first = WorkOrder::factory()->create(['received_at' => '2026-09-25 03:00:00+00']);
        $last = WorkOrder::factory()->create(['received_at' => '2026-09-26 02:59:59+00']);
        WorkOrder::factory()->create(['received_at' => '2026-09-26 03:00:00+00']);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders?from=2026-09-25&to=2026-09-25&sort=oldest')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $last->id);
        $this->getJson('/api/v1/work-orders?to=2026-09-25')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/work-orders?from=2026-09-26&to=2026-09-25')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_customer_and_technician_filters_do_not_mix_other_orders(): void
    {
        $technician = User::factory()->technician()->create();
        $target = WorkOrder::factory()->create(['assigned_technician_id' => $technician->id]);
        WorkOrder::factory()->create();
        $this->actingAs($technician)->getJson("/api/v1/work-orders?customer_id={$target->customer_id}&technician_id={$technician->id}&scope=open")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson("/api/v1/work-orders?customer_id={$target->customer_id}&scope=closed")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/work-orders?sort=invalid&scope=invalid&mine=invalid&from=invalid')
            ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'scope', 'mine', 'from']);
    }

    public function test_unavailable_stock_includes_fully_reserved_parts(): void
    {
        Part::factory()->create(['on_hand' => 0]);
        Part::factory()->create(['on_hand' => 3, 'reserved_quantity' => 3]);
        Part::factory()->create(['on_hand' => 3, 'reserved_quantity' => 1]);
        $this->actingAs(User::factory()->create())->getJson('/api/v1/parts?unavailable=1')
            ->assertOk()->assertJsonCount(2, 'data');
    }
}
