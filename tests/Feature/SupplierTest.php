<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_creates_lists_reads_and_updates_suppliers(): void
    {
        $admin = User::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/v1/suppliers', ['name' => 'Peças Sul', 'contact' => '(41) 3333-4444', 'notes' => 'Entrega em 2 dias'])
            ->assertCreated()->assertJsonPath('data.name', 'Peças Sul')->assertJsonPath('data.active', true)->json('data.id');
        Supplier::factory()->create(['name' => 'Eletrônica Norte']);

        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Eletrônica Norte');
        $this->getJson('/api/v1/suppliers?q=sul')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/suppliers/{$id}")->assertOk()->assertJsonPath('data.contact', '(41) 3333-4444');

        $this->patchJson("/api/v1/suppliers/{$id}", ['name' => 'Peças do Sul', 'active' => false])->assertOk()->assertJsonPath('data.name', 'Peças do Sul')->assertJsonPath('data.active', false);
        $this->getJson('/api/v1/suppliers?active=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/suppliers?active=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    public function test_the_name_is_required_and_unique_ignoring_case(): void
    {
        $admin = User::factory()->create();
        $existing = Supplier::factory()->create(['name' => 'Peças Sul']);

        $this->actingAs($admin)->postJson('/api/v1/suppliers', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/suppliers', ['name' => 'PEÇAS SUL'])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Já existe um fornecedor com esse nome.');
        $this->postJson('/api/v1/suppliers', ['name' => str_repeat('x', 121)])->assertUnprocessable()->assertJsonValidationErrors('name');

        // Renomear para o próprio nome (outra caixa) vale; para o de outro não.
        $other = Supplier::factory()->create(['name' => 'Outro']);
        $this->patchJson("/api/v1/suppliers/{$existing->id}", ['name' => 'peças sul'])->assertOk();
        $this->patchJson("/api/v1/suppliers/{$other->id}", ['name' => 'PEÇAS SUL'])->assertUnprocessable()->assertJsonValidationErrors('name');

        try {
            DB::table('suppliers')->insert(['name' => 'OUTRO', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->fail('o banco aceitou nome repetido em outra caixa');
        } catch (QueryException $e) {
            $this->assertStringContainsString('suppliers_name_unique', $e->getMessage());
        }
    }

    public function test_the_technician_sees_and_changes_nothing_about_suppliers(): void
    {
        $tech = User::factory()->technician()->create();
        $supplier = Supplier::factory()->create();

        $this->actingAs($tech)->getJson('/api/v1/suppliers')->assertForbidden();
        $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
        $this->postJson('/api/v1/suppliers', ['name' => 'X'])->assertForbidden();
        $this->patchJson("/api/v1/suppliers/{$supplier->id}", ['active' => false])->assertForbidden();
        $this->assertTrue($supplier->fresh()->active);
        $this->assertDatabaseCount('suppliers', 1);
    }
}
