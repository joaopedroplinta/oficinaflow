<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Device $device, array $extra = []): array
    {
        return $extra + [
            'customer_id' => $device->customer_id,
            'device_id' => $device->id,
            'reported_problem' => 'Não liga',
            'intake_condition' => 'Arranhões na tampa',
            'accessories' => 'Carregador',
        ];
    }

    public function test_admin_opens_an_order_with_intake_snapshot_and_opening_event(): void
    {
        $admin = User::factory()->create();
        $device = Device::factory()->create(['category' => 'Notebook', 'brand' => 'Dell', 'model' => 'Inspiron 15', 'serial_number' => 'ABC123']);

        $this->actingAs($admin)->postJson('/api/v1/work-orders', $this->payload($device))
            ->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.status_label', 'Recebida')
            ->assertJsonPath('data.customer.id', $device->customer_id)
            ->assertJsonPath('data.device.model', 'Inspiron 15');

        $order = WorkOrder::sole();
        $this->assertNotNull($order->number);
        $this->assertSame($device->customer_id, $order->customer_id);
        $this->assertSame('ABC123', $order->device_serial_number);
        $this->assertDatabaseHas('work_order_events', [
            'work_order_id' => $order->id, 'actor_id' => $admin->id, 'type' => 'opened',
            'from_status' => null, 'to_status' => 'received',
        ]);
    }

    public function test_editing_the_device_afterwards_does_not_change_the_order(): void
    {
        $device = Device::factory()->create(['brand' => 'Dell', 'model' => 'Inspiron 15']);
        $this->actingAs(User::factory()->create())->postJson('/api/v1/work-orders', $this->payload($device))->assertCreated();

        $device->update(['brand' => 'Lenovo', 'model' => 'ThinkPad', 'serial_number' => 'OUTRO']);

        $order = WorkOrder::sole();
        $this->assertSame('Dell', $order->device_brand);
        $this->assertSame('Inspiron 15', $order->device_model);
        $this->assertNotSame('OUTRO', $order->device_serial_number);
    }

    public function test_each_order_gets_its_own_increasing_number(): void
    {
        $admin = User::factory()->create();
        $device = Device::factory()->create();

        // A sequence do Postgres não volta com o rollback do teste, então compara só a ordem.
        $first = $this->actingAs($admin)->postJson('/api/v1/work-orders', $this->payload($device))->json('data.number');
        $second = $this->actingAs($admin)->postJson('/api/v1/work-orders', $this->payload($device))->json('data.number');

        $this->assertGreaterThan($first, $second);
        $this->assertSame(2, WorkOrder::distinct()->count('number'));
    }

    public function test_system_fields_in_the_body_are_ignored(): void
    {
        $device = Device::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/work-orders', $this->payload($device, ['status' => 'delivered', 'number' => 999, 'device_brand' => 'Fake']))
            ->assertCreated()->assertJsonPath('data.status', 'received');

        $order = WorkOrder::sole();
        $this->assertNotSame(999, $order->number);
        $this->assertSame($device->brand, $order->device_brand);
    }

    public function test_technician_cannot_open_an_order(): void
    {
        $device = Device::factory()->create();

        $this->actingAs(User::factory()->technician()->create())
            ->postJson('/api/v1/work-orders', $this->payload($device))
            ->assertForbidden();

        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_guest_gets_401(): void
    {
        $this->postJson('/api/v1/work-orders', [])->assertUnauthorized();
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/work-orders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'device_id', 'reported_problem', 'intake_condition', 'accessories']);

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_device_must_belong_to_the_customer(): void
    {
        $device = Device::factory()->create();
        $other = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/work-orders', $this->payload($device, ['customer_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('device_id');

        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_inactive_device_or_customer_is_rejected(): void
    {
        $admin = User::factory()->create();
        $inactiveDevice = Device::factory()->create(['active' => false]);
        $inactiveCustomer = Device::factory()->for(Customer::factory()->create(['active' => false]))->create();

        $this->actingAs($admin)->postJson('/api/v1/work-orders', $this->payload($inactiveDevice))
            ->assertUnprocessable()->assertJsonValidationErrors('device_id');
        $this->actingAs($admin)->postJson('/api/v1/work-orders', $this->payload($inactiveCustomer))
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $this->assertDatabaseCount('work_orders', 0);
    }
}
