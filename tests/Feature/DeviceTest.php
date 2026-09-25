<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
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
}
