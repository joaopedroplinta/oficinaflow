<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Device;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuoteIssueTest extends TestCase
{
    use RefreshDatabase;

    private function draft(array $order = ['diagnosis' => 'Fonte queimada'], bool $withItem = true): Quote
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create($order);
        $quote = Quote::factory()->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $quote->id])->save();
        if ($withItem) {
            DB::table('quote_items')->insert(['quote_id' => $quote->id, 'kind' => 'service', 'description' => 'Troca da fonte', 'quantity' => 1, 'unit_price_cents' => 12000, 'position' => 0]);
            $quote->forceFill(['total_cents' => 12000])->save();
        }

        return $quote;
    }

    public function test_admin_issues_the_quote_and_the_order_awaits_approval(): void
    {
        $quote = $this->draft();
        $admin = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/issue")
            ->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.status_label', 'Emitido')
            ->assertJsonPath('data.total_cents', 12000)->assertJsonCount(1, 'data.items');

        $this->assertNotNull($quote->fresh()->issued_at);
        $this->assertSame(WorkOrderStatus::AwaitingApproval, $quote->workOrder->fresh()->status);
        $this->assertDatabaseHas('work_order_events', [
            'work_order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'type' => 'quote_issued',
            'from_status' => 'diagnosing', 'to_status' => 'awaiting_approval',
        ]);
    }

    public function test_the_whole_path_from_intake_to_an_issued_quote_through_the_api(): void
    {
        $admin = User::factory()->create();
        $tech = User::factory()->technician()->create();
        $device = Device::factory()->create();

        $id = $this->actingAs($admin)->postJson('/api/v1/work-orders', [
            'customer_id' => $device->customer_id, 'device_id' => $device->id,
            'reported_problem' => 'Não liga', 'intake_condition' => 'Ok', 'accessories' => 'Nenhum',
        ])->json('data.id');
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$id}/start-diagnosis")->assertOk();
        $this->actingAs($tech)->patchJson("/api/v1/work-orders/{$id}/diagnosis", ['diagnosis' => 'Fonte queimada'])->assertOk();
        $quoteId = $this->actingAs($admin)->postJson("/api/v1/work-orders/{$id}/quotes")->json('data.id');
        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quoteId}/draft", ['items' => [
            ['kind' => 'service', 'description' => 'Troca da fonte', 'quantity' => 1, 'unit_price_cents' => 12000],
        ]])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quoteId}/issue")->assertOk();

        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'awaiting_approval')->assertJsonPath('data.current_quote.status', 'issued');
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$id}/events")
            ->assertJsonPath('data.3.type', 'quote_issued')->assertJsonPath('data.3.to_status_label', 'Aguardando aprovação');
    }

    public function test_technician_cannot_issue_and_guest_gets_401(): void
    {
        $quote = $this->draft();

        $this->postJson("/api/v1/quotes/{$quote->id}/issue")->assertUnauthorized();
        $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/quotes/{$quote->id}/issue")->assertForbidden();

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
    }

    public function test_issuing_requires_a_diagnosis(): void
    {
        $quote = $this->draft(['diagnosis' => '  ']);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/issue")
            ->assertUnprocessable()->assertJsonValidationErrors('diagnosis');

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
        $this->assertSame(WorkOrderStatus::Diagnosing, $quote->workOrder->fresh()->status);
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_issuing_requires_at_least_one_item(): void
    {
        $quote = $this->draft(withItem: false);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/issue")
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
    }

    public function test_issuing_twice_is_a_conflict_and_records_one_event(): void
    {
        $quote = $this->draft();
        $admin = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/issue")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/issue")
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('work_order_events', 1);
    }

    public function test_a_superseded_version_cannot_be_issued(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create(['diagnosis' => 'x']);
        $old = Quote::factory()->status(QuoteStatus::Superseded)->create(['work_order_id' => $order->id]);
        $current = Quote::factory()->create(['work_order_id' => $order->id, 'version' => 2]);
        $order->forceFill(['current_quote_id' => $current->id])->save();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$old->id}/issue")
            ->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');
    }

    public function test_cannot_issue_when_the_order_is_not_in_diagnosing(): void
    {
        $quote = $this->draft();
        $quote->workOrder->forceFill(['status' => WorkOrderStatus::AwaitingReturn, 'cancellation_reason' => 'Cancelado no teste.'])->save();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/issue")
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertSame(QuoteStatus::Draft, $quote->fresh()->status);
    }

    public function test_an_issued_version_is_immutable_through_the_api(): void
    {
        $quote = $this->draft();
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/issue")->assertOk();

        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => [
            ['kind' => 'service', 'description' => 'Outro', 'quantity' => 9, 'unit_price_cents' => 1],
        ]])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertSame(['Troca da fonte'], $quote->items()->pluck('description')->all());
        $this->assertSame(12000, $quote->fresh()->total_cents);
    }
}
