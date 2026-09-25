<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): void
    {
        config(['app.demo_password' => null]);
        app(DatabaseSeeder::class)->run();
    }

    private function orderOf(string $customer): WorkOrder
    {
        return WorkOrder::whereHas('customer', fn ($q) => $q->where('name', $customer))->sole();
    }

    public function test_the_four_example_orders_are_in_the_expected_situations(): void
    {
        $this->seedDemo();

        $this->assertSame('diagnosing', $this->orderOf('Maria Silva')->status->value);
        $this->assertNull($this->orderOf('Maria Silva')->current_quote_id);

        $waiting = $this->orderOf('João Pereira');
        $this->assertSame('approved', $waiting->status->value);
        $this->assertSame(0, PartReservation::whereHas('quoteItem.quote', fn ($q) => $q->where('work_order_id', $waiting->id))->count(), 'aprovada e sem reserva: aguardando peças');

        $revised = $this->orderOf('Carla Souza');
        $this->assertSame('awaiting_approval', $revised->status->value);
        $versions = Quote::where('work_order_id', $revised->id)->orderBy('version')->get();
        $this->assertSame(['superseded', 'issued'], $versions->pluck('status')->map->value->all());
        $this->assertSame('approved', $versions[0]->decision->decision->value, 'a aprovação da V1 fica no histórico');
        $this->assertNull($versions[1]->decision, 'a V2 exige nova aprovação');
        $this->assertSame('released', PartReservation::whereHas('quoteItem', fn ($q) => $q->where('quote_id', $versions[0]->id))->sole()->status->value);

        $ready = $this->orderOf('Pedro Alves');
        $this->assertSame('ready_for_pickup', $ready->status->value);
        $this->assertNotNull($ready->work_performed);
        $this->assertSame(2, PartReservation::where('status', 'consumed')->count());
    }

    public function test_the_demo_data_is_consistent_with_the_stock_invariants(): void
    {
        $this->seedDemo();

        foreach (Part::all() as $part) {
            $this->assertSame((int) PartReservation::where('part_id', $part->id)->where('status', 'active')->sum('quantity'), $part->reserved_quantity, "{$part->sku}: reservado");
            $this->assertSame(
                (int) InventoryMovement::where('part_id', $part->id)->where('kind', 'receipt')->sum('quantity') - (int) InventoryMovement::where('part_id', $part->id)->where('kind', 'consumption')->sum('quantity'),
                $part->on_hand,
                "{$part->sku}: o livro-razão explica o saldo",
            );
        }
        $this->assertSame(0, Part::where('sku', 'TELA-A54')->value('on_hand'), 'a tela ainda não chegou');
    }

    public function test_the_demo_users_and_the_flow_work_through_the_api(): void
    {
        $this->seedDemo();

        $this->postJson('/api/v1/login', ['email' => 'tecnico@oficinaflow.test', 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/work-orders')->assertOk()->assertJsonPath('meta.total', 4);
        $waiting = $this->orderOf('João Pereira');
        $this->getJson("/api/v1/work-orders/{$waiting->id}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.pending.0.name', 'Tela Galaxy A54')->assertJsonPath('data.pending.0.available', 0);
        $this->assertSame(2, User::count());
    }
}
