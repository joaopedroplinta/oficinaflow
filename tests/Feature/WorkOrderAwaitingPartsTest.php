<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

class WorkOrderAwaitingPartsTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private function flags(?string $query = ''): array
    {
        return collect($this->actingAs(User::factory()->technician()->create())->getJson('/api/v1/work-orders'.$query)->assertOk()->json('data'))
            ->mapWithKeys(fn ($o) => [$o['id'] => $o['awaiting_parts']])->all();
    }

    public function test_only_approved_orders_with_unreserved_parts_are_flagged(): void
    {
        $part = Part::factory()->create(['on_hand' => 10]);
        $waiting = $this->approvedOrder([[$part, 1]]);
        $reserved = $this->approvedOrder([[$part, 1]]);
        $laborOnly = $this->approvedOrder([]);
        $notApproved = $this->approvedOrder([[$part, 1]], WorkOrderStatus::AwaitingApproval);
        $plain = WorkOrder::factory()->create();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$reserved->work_order_id}/reservations", ['quote_id' => $reserved->id])->assertOk();

        $flags = $this->flags();

        $this->assertTrue($flags[$waiting->work_order_id], 'aprovada com peça sem reserva');
        $this->assertFalse($flags[$reserved->work_order_id], 'aprovada e reservada');
        $this->assertFalse($flags[$laborOnly->work_order_id], 'só mão de obra');
        $this->assertFalse($flags[$notApproved->work_order_id], 'ainda não aprovada');
        $this->assertFalse($flags[$plain->id], 'sem orçamento');

        // A listagem e o detalhe concordam: o flag é "pending não vazio".
        foreach ($flags as $id => $flag) {
            $pending = $this->getJson("/api/v1/work-orders/{$id}")->json('data.pending');
            $this->assertSame($flag, count($pending) > 0, "flag da listagem diferente do pending do detalhe (ordem {$id})");
        }
    }

    public function test_the_flag_goes_away_once_the_parts_are_reserved(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();

        $this->assertTrue($this->flags()[$quote->work_order_id]);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertConflict();
        $this->assertTrue($this->flags()[$quote->work_order_id], 'sem estoque, continua aguardando');

        $this->actingAs($admin)->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => 2])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertOk();
        $this->assertFalse($this->flags()[$quote->work_order_id]);
    }

    public function test_the_list_can_be_filtered_by_awaiting_parts(): void
    {
        $part = Part::factory()->create();
        $waiting = $this->approvedOrder([[$part, 1]]);
        $this->approvedOrder([]);
        WorkOrder::factory()->count(2)->create();

        $tech = User::factory()->technician()->create();
        $this->actingAs($tech)->getJson('/api/v1/work-orders?awaiting_parts=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $waiting->work_order_id)->assertJsonPath('meta.total', 1);
        $this->actingAs($tech)->getJson('/api/v1/work-orders?awaiting_parts=abc')->assertUnprocessable()->assertJsonValidationErrors('awaiting_parts');
        $this->actingAs($tech)->getJson('/api/v1/work-orders?awaiting_parts=0')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_the_list_does_not_run_more_queries_when_there_are_more_orders(): void
    {
        $part = Part::factory()->create();
        $tech = User::factory()->technician()->create();
        $count = function () use ($tech) {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->actingAs($tech)->getJson('/api/v1/work-orders')->assertOk();

            return $queries;
        };

        $this->approvedOrder([[$part, 1]]);
        $few = $count();
        foreach (range(1, 12) as $i) {
            $this->approvedOrder([[$part, 1]]);
        }
        $many = $count();

        $this->assertSame($few, $many, 'a listagem não pode fazer uma consulta por ordem (N+1)');
    }

    public function test_the_detail_does_not_carry_the_list_flag_but_keeps_pending(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $quote = $this->approvedOrder([[$part, 1]]);

        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$quote->work_order_id}")
            ->assertOk()->assertJsonMissingPath('data.awaiting_parts')->assertJsonCount(1, 'data.pending');
    }
}
