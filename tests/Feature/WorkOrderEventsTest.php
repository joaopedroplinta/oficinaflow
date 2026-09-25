<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_lists_events_in_order_with_author_type_and_statuses(): void
    {
        $admin = User::factory()->create(['name' => 'Ana']);
        $tech = User::factory()->technician()->create(['name' => 'Tiago']);
        $device = Device::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/v1/work-orders', [
            'customer_id' => $device->customer_id, 'device_id' => $device->id,
            'reported_problem' => 'Não liga', 'intake_condition' => 'Ok', 'accessories' => 'Nenhum',
        ])->json('data.id');
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$id}/start-diagnosis")->assertOk();
        $this->actingAs($tech)->patchJson("/api/v1/work-orders/{$id}/diagnosis", ['diagnosis' => 'Fonte'])->assertOk();

        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$id}/events")
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'opened')
            ->assertJsonPath('data.0.type_label', 'Ordem aberta')
            ->assertJsonPath('data.0.from_status', null)
            ->assertJsonPath('data.0.to_status_label', 'Recebida')
            ->assertJsonPath('data.0.actor.name', 'Ana')
            ->assertJsonPath('data.1.type', 'diagnosis_started')
            ->assertJsonPath('data.1.from_status_label', 'Recebida')
            ->assertJsonPath('data.1.to_status_label', 'Em diagnóstico')
            ->assertJsonPath('data.1.actor.name', 'Tiago')
            ->assertJsonPath('data.2.type', 'diagnosis_recorded');
    }

    public function test_only_events_of_that_order_are_returned(): void
    {
        $user = User::factory()->create();
        $mine = WorkOrder::factory()->create();
        $other = WorkOrder::factory()->status(WorkOrderStatus::Received)->create();
        $this->actingAs($user)->postJson("/api/v1/work-orders/{$other->id}/start-diagnosis")->assertOk();

        $this->actingAs($user)->getJson("/api/v1/work-orders/{$mine->id}/events")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guest_gets_401_and_unknown_order_404(): void
    {
        $order = WorkOrder::factory()->create();

        $this->getJson("/api/v1/work-orders/{$order->id}/events")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders/999999/events')->assertNotFound();
    }
}
