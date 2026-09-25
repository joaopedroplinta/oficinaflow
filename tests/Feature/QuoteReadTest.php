<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuoteReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_come_newest_first_with_items_and_decisions(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::AwaitingApproval)->create(['diagnosis' => 'x']);
        $admin = User::factory()->create();
        $v1 = Quote::factory()->create(['work_order_id' => $order->id]);
        $v1->items()->create(['kind' => 'service', 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 5000, 'position' => 0]);
        $v1->forceFill(['status' => QuoteStatus::Issued, 'total_cents' => 5000])->save();
        $order->forceFill(['current_quote_id' => $v1->id])->save();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v1->id}/decision", ['decision' => 'rejected', 'customer_name' => 'Maria', 'channel' => 'phone'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/quote-revisions", ['quote_id' => $v1->id])->assertCreated();

        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$order->id}/quotes")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)->assertJsonPath('data.0.status', 'draft')->assertJsonPath('data.0.decision', null)
            ->assertJsonPath('data.1.version', 1)->assertJsonPath('data.1.status_label', 'Substituído')
            ->assertJsonPath('data.1.decision.decision_label', 'Rejeitado')->assertJsonPath('data.1.decision.recorded_by.name', $admin->name)
            ->assertJsonPath('data.1.items.0.description', 'Mão de obra');
    }

    public function test_only_versions_of_that_order_and_auth_is_required(): void
    {
        $mine = WorkOrder::factory()->create();
        Quote::factory()->create();

        $this->getJson("/api/v1/work-orders/{$mine->id}/quotes")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson("/api/v1/work-orders/{$mine->id}/quotes")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create())->getJson('/api/v1/work-orders/999999/quotes')->assertNotFound();
    }

    public function test_parts_list_can_be_limited_to_active_parts_and_shows_availability(): void
    {
        Part::factory()->create(['name' => 'Bateria', 'on_hand' => 5, 'reserved_quantity' => 2, 'sale_price_cents' => 15000]);
        Part::factory()->create(['name' => 'Inativa', 'active' => false]);

        $this->getJson('/api/v1/parts')->assertUnauthorized();
        $this->actingAs(User::factory()->technician()->create())->getJson('/api/v1/parts?active=1&per_page=500')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bateria')->assertJsonPath('data.0.available', 3)->assertJsonPath('data.0.sale_price_cents', 15000);
    }
}
