<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['category' => 'Notebook', 'brand' => 'Dell', 'model' => 'Inspiron 15', 'serial_number' => 'ABC123'];

    public function test_admin_registers_a_device_for_the_customer_in_the_route(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();

        // customer_id no corpo é ignorado: quem define é a rota.
        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/customers/{$customer->id}/devices", $this->payload + ['customer_id' => $other->id])
            ->assertCreated()->assertJsonPath('data.customer_id', $customer->id)->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('devices', ['customer_id' => $customer->id, 'model' => 'Inspiron 15']);
        $this->assertDatabaseMissing('devices', ['customer_id' => $other->id]);
    }

    public function test_technician_cannot_register_a_device(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->technician()->create())
            ->postJson("/api/v1/customers/{$customer->id}/devices", $this->payload)
            ->assertForbidden();

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_required_fields_are_validated(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/customers/{$customer->id}/devices", ['category' => 'Notebook'])
            ->assertUnprocessable()->assertJsonValidationErrors(['brand', 'model']);

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_lists_only_the_devices_of_that_customer(): void
    {
        $customer = Customer::factory()->create();
        Device::factory()->count(2)->for($customer)->create();
        Device::factory()->create();

        $this->actingAs(User::factory()->technician()->create())
            ->getJson("/api/v1/customers/{$customer->id}/devices")
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_inactive_customer_cannot_get_new_devices(): void
    {
        $customer = Customer::factory()->create(['active' => false]);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/customers/{$customer->id}/devices", $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('customer');

        $this->assertDatabaseCount('devices', 0);
    }

    public function test_technician_still_gets_403_before_the_inactive_check(): void
    {
        $customer = Customer::factory()->create(['active' => false]);

        $this->actingAs(User::factory()->technician()->create())
            ->postJson("/api/v1/customers/{$customer->id}/devices", $this->payload)->assertForbidden();
    }

    public function test_admin_edits_the_device_data(): void
    {
        $device = Device::factory()->create(['brand' => 'Dell']);

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/devices/{$device->id}", ['brand' => 'Lenovo', 'model' => 'ThinkPad', 'serial_number' => null])
            ->assertOk()->assertJsonPath('data.brand', 'Lenovo')->assertJsonPath('data.serial_number', null);

        $this->assertDatabaseHas('devices', ['id' => $device->id, 'brand' => 'Lenovo', 'model' => 'ThinkPad', 'serial_number' => null]);
    }

    public function test_admin_deactivates_and_reactivates_the_device(): void
    {
        $device = Device::factory()->create();
        $admin = User::factory()->create();

        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}", ['active' => true])
            ->assertOk()->assertJsonPath('data.active', true);
    }

    public function test_customer_of_a_device_cannot_be_changed(): void
    {
        $device = Device::factory()->create();
        $other = Customer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/devices/{$device->id}", ['customer_id' => $other->id, 'brand' => 'X'])->assertOk();

        $this->assertNotSame($other->id, $device->fresh()->customer_id);
    }

    public function test_technician_cannot_edit_a_device(): void
    {
        $device = Device::factory()->create(['brand' => 'Dell']);

        $this->actingAs(User::factory()->technician()->create())
            ->patchJson("/api/v1/devices/{$device->id}", ['brand' => 'Lenovo'])->assertForbidden();

        $this->assertSame('Dell', $device->fresh()->brand);
    }

    public function test_blank_required_fields_are_rejected_on_edit(): void
    {
        $device = Device::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/devices/{$device->id}", ['brand' => '', 'model' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['brand', 'model']);
    }

    public function test_unknown_device_is_404(): void
    {
        $this->actingAs(User::factory()->create())->patchJson('/api/v1/devices/999999', ['brand' => 'X'])->assertNotFound();
    }

    public function test_editing_a_device_does_not_change_the_snapshot_of_an_existing_order(): void
    {
        $device = Device::factory()->create(['brand' => 'Dell', 'model' => 'Inspiron 15', 'serial_number' => 'ABC123']);
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson('/api/v1/work-orders', [
            'customer_id' => $device->customer_id, 'device_id' => $device->id,
            'reported_problem' => 'Não liga', 'intake_condition' => 'Ok', 'accessories' => 'Nenhum',
        ])->assertCreated();

        $this->actingAs($admin)->patchJson("/api/v1/devices/{$device->id}", ['brand' => 'Lenovo', 'model' => 'ThinkPad', 'serial_number' => 'XYZ'])->assertOk();

        $order = WorkOrder::sole();
        $this->assertSame(['Dell', 'Inspiron 15', 'ABC123'], [$order->device_brand, $order->device_model, $order->device_serial_number]);
    }
}
