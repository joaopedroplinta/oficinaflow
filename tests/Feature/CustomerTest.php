<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['name' => 'Maria Silva', 'phone' => '(41) 99999-0000', 'email' => 'maria@example.com'];

    public function test_admin_creates_a_customer(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/customers', $this->payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Maria Silva')
            ->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('customers', ['name' => 'Maria Silva', 'phone' => '(41) 99999-0000']);
    }

    public function test_technician_cannot_create_a_customer(): void
    {
        $this->actingAs(User::factory()->technician()->create())
            ->postJson('/api/v1/customers', $this->payload)
            ->assertForbidden();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/v1/customers', $this->payload)->assertUnauthorized();
        $this->getJson('/api/v1/customers')->assertUnauthorized();
    }

    public function test_invalid_data_returns_portuguese_errors_and_saves_nothing(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/customers', ['name' => '', 'phone' => 'abc', 'email' => 'nao-e-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone', 'email'])
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_search_filters_by_name_or_phone_and_paginates(): void
    {
        Customer::factory()->create(['name' => 'Ana Souza', 'phone' => '(41) 91111-1111', 'email' => 'a@example.com']);
        Customer::factory()->create(['name' => 'Bruno Lima', 'phone' => '(41) 92222-2222', 'email' => 'b@example.com']);
        $this->actingAs(User::factory()->technician()->create());

        $this->getJson('/api/v1/customers?q=ana')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ana Souza');
        $this->getJson('/api/v1/customers?q=2222')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bruno Lima');
        $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['meta' => ['total']]);
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        Customer::factory()->create(['name' => 'Ana Souza', 'email' => 'a@example.com']);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/customers?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_updates_and_deactivates_but_technician_cannot(): void
    {
        $customer = Customer::factory()->create(['name' => 'Antigo']);

        $this->actingAs(User::factory()->technician()->create())
            ->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Novo'])
            ->assertForbidden();
        $this->assertSame('Antigo', $customer->fresh()->name);

        $this->actingAs(User::factory()->create())
            ->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Novo', 'active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Novo')->assertJsonPath('data.active', false);
    }

    public function test_show_returns_404_for_unknown_customer(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/customers/999')->assertNotFound();
    }
}
