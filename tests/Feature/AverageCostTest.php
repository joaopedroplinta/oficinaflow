<?php

namespace Tests\Feature;

use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\Quote;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Documents;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Custo nas entradas, custo médio ponderado e margem (PLANO §13.3). */
class AverageCostTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    private function receive(Part $part, int $quantity, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => $quantity] + $extra);
    }

    public function test_costed_receipts_build_the_weighted_average_and_uncosted_ones_only_move_the_balance(): void
    {
        $part = Part::factory()->create(['on_hand' => 0, 'sale_price_cents' => 1000]);
        $this->assertNull($part->average_cost_cents);

        $this->receive($part, 10, ['unit_cost_cents' => 100])->assertOk()->assertJsonPath('data.average_cost_cents', 100);
        $this->receive($part, 10, ['unit_cost_cents' => 200])->assertOk()->assertJsonPath('data.average_cost_cents', 150);
        $this->receive($part, 10)->assertOk()->assertJsonPath('data.average_cost_cents', 150)->assertJsonPath('data.on_hand', 30);   // sem custo: só o saldo
        // As 10 unidades sem custo pesam pelo custo médio atual: (30×150 + 10×300) ÷ 40 = 187,5 → 188.
        $this->receive($part, 10, ['unit_cost_cents' => 300])->assertOk()->assertJsonPath('data.average_cost_cents', 188)->assertJsonPath('data.on_hand', 40);

        $this->assertSame([100, 200, null, 300], DB::table('inventory_movements')->orderBy('id')->pluck('unit_cost_cents')->map(fn ($c) => $c === null ? null : (int) $c)->all());
    }

    public function test_the_first_costed_entry_ignores_uncosted_stock_and_a_zero_cost_is_valid(): void
    {
        $part = Part::factory()->create(['on_hand' => 7]);

        $this->receive($part, 3, ['unit_cost_cents' => 500])->assertOk()->assertJsonPath('data.average_cost_cents', 500);
        $free = Part::factory()->create(['on_hand' => 0]);
        $this->receive($free, 4, ['unit_cost_cents' => 0])->assertOk()->assertJsonPath('data.average_cost_cents', 0);
    }

    public function test_the_receipt_records_the_supplier_and_validates_cost_and_supplier(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $supplier = Supplier::factory()->create(['name' => 'Peças Sul']);
        $inactive = Supplier::factory()->create(['active' => false]);

        $this->receive($part, 2, ['unit_cost_cents' => 100, 'supplier_id' => $supplier->id])->assertOk();
        $this->actingAs($this->admin)->getJson("/api/v1/parts/{$part->id}/movements")->assertOk()
            ->assertJsonPath('data.0.unit_cost_cents', 100)->assertJsonPath('data.0.supplier.name', 'Peças Sul');

        $this->receive($part, 1, ['supplier_id' => $inactive->id])->assertUnprocessable()->assertJsonPath('errors.supplier_id.0', 'Fornecedor não encontrado ou inativo.');
        $this->receive($part, 1, ['supplier_id' => 9999])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
        foreach ([-1, 100_000_001, 'abc', 1.5] as $bad) {
            $this->receive($part, 1, ['unit_cost_cents' => $bad])->assertUnprocessable()->assertJsonValidationErrors('unit_cost_cents');
        }
        $this->assertSame(2, $part->fresh()->on_hand, 'as recusadas não mexeram no saldo');
        $this->assertSame(100, $part->fresh()->average_cost_cents);
    }

    public function test_consumption_and_return_do_not_change_the_average(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $this->receive($part, 5, ['unit_cost_cents' => 400])->assertOk();
        $quote = $this->approvedOrder([[$part->fresh(), 2]]);
        $order = WorkOrder::find($quote->work_order_id);
        app(ReserveParts::class)->handle($order, $quote->id, $this->admin);
        app(StartRepair::class)->handle($order, $quote->id, $this->admin);
        $this->assertSame(400, $part->fresh()->average_cost_cents);

        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$order->id}/cancel", ['reason' => 'Desistência', 'parts' => [['part_id' => $part->id, 'action' => 'return', 'quantity' => 2]]])->assertOk();

        $this->assertSame([5, 400], [$part->fresh()->on_hand, $part->fresh()->average_cost_cents]);
    }

    public function test_only_the_admin_sees_cost_and_margin_of_a_part(): void
    {
        $part = Part::factory()->create(['on_hand' => 0, 'sale_price_cents' => 1000]);
        $noCost = Part::factory()->create(['sale_price_cents' => 1000]);
        $free = Part::factory()->create(['on_hand' => 0, 'sale_price_cents' => 0]);
        $this->receive($part, 2, ['unit_cost_cents' => 600]);
        $this->receive($free, 2, ['unit_cost_cents' => 100]);

        $this->actingAs($this->admin)->getJson("/api/v1/parts/{$part->id}")
            ->assertJsonPath('data.average_cost_cents', 600)->assertJsonPath('data.margin_cents', 400)->assertJsonPath('data.margin_percent', 40);
        $this->getJson("/api/v1/parts/{$noCost->id}")->assertJsonPath('data.average_cost_cents', null)->assertJsonPath('data.margin_cents', null)->assertJsonPath('data.margin_percent', null);
        $this->getJson("/api/v1/parts/{$free->id}")->assertJsonPath('data.margin_cents', -100)->assertJsonPath('data.margin_percent', null);

        $tech = User::factory()->technician()->create();
        foreach ([["/api/v1/parts/{$part->id}"], ['/api/v1/parts']] as [$url]) {
            $json = $this->actingAs($tech)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('average_cost', $json);
            $this->assertStringNotContainsString('margin', $json);
        }
    }

    public function test_the_quote_shows_current_cost_and_margin_to_the_admin_only(): void
    {
        $a = Part::factory()->create(['on_hand' => 0, 'sale_price_cents' => 1000, 'name' => 'Fonte']);
        $b = Part::factory()->create(['on_hand' => 0, 'sale_price_cents' => 500, 'name' => 'Cabo']);
        $this->receive($a, 5, ['unit_cost_cents' => 600]);
        $quote = $this->approvedOrder([[$a->fresh(), 2], [$b->fresh(), 1]]);       // 2×10,00 + 1×5,00 + serviço 50,00 = 75,00
        $url = "/api/v1/work-orders/{$quote->work_order_id}/quotes";

        $res = $this->actingAs($this->admin)->getJson($url)->assertOk();
        $items = collect($res->json('data.0.items'))->where('kind', 'part')->keyBy('description');
        $this->assertSame([600, 800], [$items['Fonte']['unit_cost_cents'], $items['Fonte']['line_margin_cents']]);
        $this->assertNull($items['Cabo']['unit_cost_cents'], 'peça sem custo médio ainda');
        $this->assertNull($items['Cabo']['line_margin_cents']);
        $res->assertJsonPath('data.0.cost_cents', 1200)->assertJsonPath('data.0.margin_cents', 6300)->assertJsonPath('data.0.cost_complete', false);
        $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertJsonPath('data.current_quote.cost_cents', 1200)->assertJsonPath('data.current_quote.cost_complete', false);

        $json = $this->actingAs(User::factory()->technician()->create())->getJson($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('cost', $json);
        $this->assertStringNotContainsString('margin', $json);
    }

    public function test_the_customer_documents_never_carry_cost_or_margin(): void
    {
        $part = Part::factory()->create(['on_hand' => 0, 'sale_price_cents' => 1000, 'name' => 'Fonte']);
        $this->receive($part, 5, ['unit_cost_cents' => 777]);
        $quote = $this->approvedOrder([[$part->fresh(), 1]], WorkOrderStatus::InProgress);

        foreach ([Documents::quote(Quote::find($quote->id)), Documents::workOrder($quote->workOrder->fresh())] as $html) {
            $this->assertStringNotContainsString('R$ 7,77', $html);
            $this->assertStringNotContainsStringIgnoringCase('custo', $html);
            $this->assertStringNotContainsStringIgnoringCase('margem', $html);
        }
    }

    public function test_the_database_keeps_cost_and_supplier_on_receipts_only(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $this->receive($part, 3, ['unit_cost_cents' => 100])->assertOk();
        $supplier = Supplier::factory()->create();
        $consumptionRow = ['part_id' => $part->id, 'kind' => 'consumption', 'quantity' => 1, 'reservation_id' => 1, 'reason' => 'x', 'created_by' => $this->admin->id, 'created_at' => now()];

        foreach ([['unit_cost_cents' => 5], ['supplier_id' => $supplier->id]] as $extra) {
            try {
                DB::transaction(fn () => DB::table('inventory_movements')->insert($consumptionRow + $extra));
                $this->fail('o banco aceitou custo/fornecedor fora de uma entrada');
            } catch (QueryException $e) {
                $this->assertStringContainsString('inventory_movements_receipt_only_check', $e->getMessage());
            }
        }
        foreach ([[100_000_001, 'inventory_movements_cost_check'], [-1, 'inventory_movements_cost_check']] as [$cost]) {
            try {
                DB::transaction(fn () => DB::table('inventory_movements')->insert(['part_id' => $part->id, 'kind' => 'receipt', 'quantity' => 1, 'reason' => 'x', 'created_by' => $this->admin->id, 'created_at' => now(), 'unit_cost_cents' => $cost]));
                $this->fail("o banco aceitou o custo {$cost}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            DB::transaction(fn () => DB::statement('update parts set average_cost_cents = -1 where id = ?', [$part->id]));
            $this->fail('o banco aceitou custo médio negativo');
        } catch (QueryException $e) {
            $this->assertStringContainsString('parts_average_cost_check', $e->getMessage());
        }
    }
}
