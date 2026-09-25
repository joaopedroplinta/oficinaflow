<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['sku' => 'TELA-15', 'name' => 'Tela 15"', 'sale_price_cents' => 25000];

    public function test_admin_creates_a_part_with_zero_balance(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/parts', $this->payload + ['on_hand' => 99, 'reserved_quantity' => 5])
            ->assertCreated()->assertJsonPath('data.sku', 'TELA-15')->assertJsonPath('data.on_hand', 0)
            ->assertJsonPath('data.reserved_quantity', 0)->assertJsonPath('data.available', 0)->assertJsonPath('data.active', true);

        $this->assertDatabaseHas('parts', ['sku' => 'TELA-15', 'on_hand' => 0, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_create_validates_and_sku_is_unique(): void
    {
        $admin = User::factory()->create();
        Part::factory()->create(['sku' => 'TELA-15']);

        $this->actingAs($admin)->postJson('/api/v1/parts', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->actingAs($admin)->postJson('/api/v1/parts', [])->assertUnprocessable()->assertJsonValidationErrors(['sku', 'name', 'sale_price_cents']);
        $this->actingAs($admin)->postJson('/api/v1/parts', ['sku' => 'X', 'name' => 'x', 'sale_price_cents' => -1])->assertUnprocessable()->assertJsonValidationErrors('sale_price_cents');
        $this->actingAs($admin)->postJson('/api/v1/parts', ['sku' => 'X', 'name' => 'x', 'sale_price_cents' => 100000001])->assertUnprocessable()->assertJsonValidationErrors('sale_price_cents');
    }

    public function test_technician_can_read_but_never_write_or_see_movements(): void
    {
        $part = Part::factory()->create();
        $tech = User::factory()->technician()->create();

        $this->actingAs($tech)->getJson('/api/v1/parts')->assertOk();
        $this->actingAs($tech)->getJson("/api/v1/parts/{$part->id}")->assertOk();
        $this->actingAs($tech)->postJson('/api/v1/parts', $this->payload)->assertForbidden();
        $this->actingAs($tech)->patchJson("/api/v1/parts/{$part->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($tech)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 1])->assertForbidden();
        $this->actingAs($tech)->getJson("/api/v1/parts/{$part->id}/movements")->assertForbidden();

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(10, $part->fresh()->on_hand);
    }

    public function test_guest_gets_401(): void
    {
        $part = Part::factory()->create();

        $this->postJson('/api/v1/parts', $this->payload)->assertUnauthorized();
        $this->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 1])->assertUnauthorized();
    }

    public function test_update_changes_name_price_and_activity_but_never_balance_or_sku(): void
    {
        $part = Part::factory()->create(['sku' => 'ORIG', 'on_hand' => 10, 'reserved_quantity' => 2]);

        $this->actingAs(User::factory()->create())->patchJson("/api/v1/parts/{$part->id}", [
            'name' => 'Novo nome', 'sale_price_cents' => 1234, 'active' => false,
            'sku' => 'HACK', 'on_hand' => 999, 'reserved_quantity' => 0,
        ])->assertOk()->assertJsonPath('data.name', 'Novo nome')->assertJsonPath('data.active', false);

        $fresh = $part->fresh();
        $this->assertSame(['ORIG', 10, 2, 1234], [$fresh->sku, $fresh->on_hand, $fresh->reserved_quantity, $fresh->sale_price_cents]);
    }

    public function test_list_filters_search_and_paginates(): void
    {
        Part::factory()->create(['sku' => 'BAT-1', 'name' => 'Bateria']);
        Part::factory()->create(['sku' => 'TEL-1', 'name' => 'Tela', 'active' => false]);
        Part::factory()->count(20)->create();
        $admin = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/v1/parts?q=bat')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BAT-1');
        $this->actingAs($admin)->getJson('/api/v1/parts?q=tel-1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin)->getJson('/api/v1/parts?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson('/api/v1/parts?active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'TEL-1');
        $this->actingAs($admin)->getJson('/api/v1/parts')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
        $this->actingAs($admin)->getJson('/api/v1/parts?per_page=500')->assertOk()->assertJsonCount(22, 'data');
        $this->actingAs($admin)->getJson('/api/v1/parts?per_page=501')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_a_receipt_raises_the_balance_and_records_the_movement(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $admin = User::factory()->create(['name' => 'Ana']);

        $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 7, 'reason' => 'Compra NF 123'])
            ->assertOk()->assertJsonPath('data.on_hand', 10)->assertJsonPath('data.available', 10);

        $this->assertDatabaseHas('inventory_movements', ['part_id' => $part->id, 'kind' => 'receipt', 'quantity' => 7, 'reason' => 'Compra NF 123', 'created_by' => $admin->id, 'reservation_id' => null]);
        $this->actingAs($admin)->getJson("/api/v1/parts/{$part->id}/movements")
            ->assertOk()->assertJsonPath('data.0.kind_label', 'Entrada')->assertJsonPath('data.0.quantity', 7)->assertJsonPath('data.0.author.name', 'Ana');
    }

    public function test_the_reason_defaults_and_invalid_quantities_are_rejected(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $admin = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 1])->assertOk();
        $this->assertDatabaseHas('inventory_movements', ['part_id' => $part->id, 'reason' => 'Entrada de estoque']);

        foreach ([0, -5, 'abc', 1.5, 100001] as $bad) {
            $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => $bad])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", [])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(1, $part->fresh()->on_hand);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_the_balance_has_a_ceiling_and_nothing_is_written_when_it_is_hit(): void
    {
        $part = Part::factory()->create(['on_hand' => 999_999]);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertSame(999_999, $part->fresh()->on_hand);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_unknown_part_is_404(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/parts/999999/receipts', ['quantity' => 1])->assertNotFound();
    }

    public function test_movements_explain_the_balance(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $admin = User::factory()->create();

        foreach ([5, 3, 12] as $q) {
            $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => $q])->assertOk();
        }

        $this->assertSame($part->fresh()->on_hand, (int) InventoryMovement::where('part_id', $part->id)->sum('quantity'));
        $this->actingAs($admin)->getJson("/api/v1/parts/{$part->id}/movements")->assertJsonPath('data.0.quantity', 12)->assertJsonCount(3, 'data');
    }

    public function test_the_ledger_is_append_only_and_kind_matches_reservation_in_the_database(): void
    {
        $part = Part::factory()->create();
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 4])->assertOk();
        $row = ['part_id' => $part->id, 'quantity' => 1, 'reason' => 'x', 'created_by' => $admin->id];

        foreach ([
            fn () => DB::table('inventory_movements')->update(['quantity' => 99]),
            fn () => DB::table('inventory_movements')->delete(),
            fn () => DB::table('inventory_movements')->insert($row + ['kind' => 'receipt', 'reservation_id' => 1]),
            fn () => DB::table('inventory_movements')->insert($row + ['kind' => 'consumption', 'reservation_id' => null]),
            fn () => DB::table('inventory_movements')->insert($row + ['kind' => 'gift', 'reservation_id' => null]),
            fn () => DB::table('inventory_movements')->insert(['quantity' => 0, 'kind' => 'receipt', 'reservation_id' => null] + $row),
        ] as $sql) {
            try {
                DB::transaction($sql);
                $this->fail('O banco aceitou uma linha inválida no livro-razão.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseCount('inventory_movements', 1);
    }
}
