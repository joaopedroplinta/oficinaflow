<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderListTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_technician_list_newest_first(): void
    {
        $old = WorkOrder::factory()->create();
        $new = WorkOrder::factory()->create();

        foreach ([User::factory()->create(), User::factory()->technician()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/v1/work-orders')
                ->assertOk()
                ->assertJsonPath('data.0.id', $new->id)
                ->assertJsonPath('data.1.id', $old->id)
                ->assertJsonPath('data.0.status_label', 'Recebida')
                ->assertJsonPath('meta.total', 2);
        }
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/v1/work-orders')->assertUnauthorized();
        $this->getJson('/api/v1/work-orders/1')->assertUnauthorized();
    }

    public function test_paginates_by_20(): void
    {
        WorkOrder::factory()->count(21)->create();

        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders')
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_filters_by_status(): void
    {
        WorkOrder::factory()->create();
        $diagnosing = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();

        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders?status=diagnosing')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $diagnosing->id);
    }

    public function test_filters_by_number(): void
    {
        WorkOrder::factory()->create();
        // refresh() traz o `number` gerado pelo banco.
        $target = WorkOrder::factory()->create()->refresh();

        $this->actingAs(User::factory()->create())->getJson("/api/v1/work-orders?number={$target->number}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_filters_by_customer_name(): void
    {
        WorkOrder::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Maria Silva']);
        $device = Device::factory()->for($customer)->create();
        $target = WorkOrder::factory()->create(['customer_id' => $customer->id, 'device_id' => $device->id]);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders?customer=maria')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/work-orders?status=qualquer&number=abc')
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'number']);
    }

    public function test_detail_shows_intake_snapshot_and_empty_quote_reservations_and_pending(): void
    {
        $order = WorkOrder::factory()->create(['device_brand' => 'Dell'])->refresh();

        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.number', $order->number)
            ->assertJsonPath('data.device.brand', 'Dell')
            ->assertJsonPath('data.customer.id', $order->customer_id)
            ->assertJsonPath('data.current_quote', null)
            ->assertJsonPath('data.reservations', [])
            ->assertJsonPath('data.pending', []);
    }

    public function test_detail_of_unknown_order_is_404(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders/999999')->assertNotFound();
    }

    public function test_technician_stays_read_only(): void
    {
        $order = WorkOrder::factory()->create();

        $this->actingAs(User::factory()->technician()->create())
            ->postJson('/api/v1/work-orders', ['device_id' => $order->device_id])->assertForbidden();
    }
}
