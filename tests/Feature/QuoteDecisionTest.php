<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuoteDecisionTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = ['decision' => 'approved', 'customer_name' => 'Maria Silva', 'channel' => 'whatsapp'];

    /** Ordem aguardando aprovação, com a versão atual emitida. */
    private function issued(): Quote
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::AwaitingApproval)->create(['diagnosis' => 'Fonte']);
        $quote = Quote::factory()->status(QuoteStatus::Issued)->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $quote->id])->save();

        return $quote;
    }

    public function test_approving_moves_the_order_to_approved_and_records_who_said_what(): void
    {
        $quote = $this->issued();
        $admin = User::factory()->create(['name' => 'Ana']);

        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload + ['notes' => 'Ligou às 10h', 'decided_at' => now()->subHour()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.decision.decision', 'approved')
            ->assertJsonPath('data.decision.decision_label', 'Aprovado')
            ->assertJsonPath('data.decision.customer_name', 'Maria Silva')
            ->assertJsonPath('data.decision.channel_label', 'WhatsApp')
            ->assertJsonPath('data.decision.recorded_by.name', 'Ana');

        $this->assertSame(WorkOrderStatus::Approved, $quote->workOrder->fresh()->status);
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $quote->id, 'decision' => 'approved', 'customer_name' => 'Maria Silva', 'channel' => 'whatsapp', 'recorded_by' => $admin->id]);
        $this->assertDatabaseHas('work_order_events', [
            'work_order_id' => $quote->work_order_id, 'actor_id' => $admin->id, 'type' => 'quote_approved',
            'from_status' => 'awaiting_approval', 'to_status' => 'approved',
        ]);
    }

    public function test_rejecting_sends_the_order_back_to_diagnosing_and_keeps_the_version(): void
    {
        $quote = $this->issued();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/decision", ['decision' => 'rejected'] + $this->payload)
            ->assertOk()->assertJsonPath('data.decision.decision', 'rejected');

        $order = $quote->workOrder->fresh();
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->status);
        $this->assertSame($quote->id, $order->current_quote_id);
        $this->assertSame(QuoteStatus::Issued, $quote->fresh()->status);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'type' => 'quote_rejected', 'to_status' => 'diagnosing']);
    }

    public function test_the_decision_date_defaults_to_now(): void
    {
        $quote = $this->issued();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertOk();

        $this->assertTrue($quote->decision->decided_at->diffInSeconds(now(), true) < 60);
    }

    public function test_one_decision_per_version(): void
    {
        $quote = $this->issued();
        $admin = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", ['decision' => 'rejected'] + $this->payload)
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quote_decisions', 1);
        $this->assertSame(WorkOrderStatus::Approved, $quote->workOrder->fresh()->status);
        $this->assertDatabaseCount('work_order_events', 1);
    }

    public function test_a_superseded_version_answers_quote_superseded_and_records_nothing(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::AwaitingApproval)->create(['diagnosis' => 'Fonte']);
        $old = Quote::factory()->status(QuoteStatus::Superseded)->create(['work_order_id' => $order->id, 'version' => 1]);
        $current = Quote::factory()->status(QuoteStatus::Issued)->create(['work_order_id' => $order->id, 'version' => 2]);
        $order->forceFill(['current_quote_id' => $current->id])->save();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$old->id}/decision", $this->payload)
            ->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        $this->assertDatabaseCount('quote_decisions', 0);
        $this->assertSame(WorkOrderStatus::AwaitingApproval, $order->fresh()->status);
    }

    public function test_a_draft_cannot_be_decided(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::AwaitingApproval)->create();
        $draft = Quote::factory()->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $draft->id])->save();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$draft->id}/decision", $this->payload)
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quote_decisions', 0);
    }

    public function test_the_order_must_be_awaiting_approval(): void
    {
        $quote = $this->issued();
        $quote->workOrder->forceFill(['status' => WorkOrderStatus::AwaitingReturn, 'cancellation_reason' => 'Cancelado no teste.'])->save();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quote_decisions', 0);
    }

    public function test_technician_cannot_decide_and_guest_gets_401(): void
    {
        $quote = $this->issued();

        $this->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertUnauthorized();
        $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertForbidden();

        $this->assertDatabaseCount('quote_decisions', 0);
        $this->assertSame(WorkOrderStatus::AwaitingApproval, $quote->workOrder->fresh()->status);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $quote = $this->issued();
        $admin = User::factory()->create();

        $cases = [
            'decision' => ['decision' => 'maybe'],
            'customer_name' => ['customer_name' => ''],
            'channel' => ['channel' => 'pombo'],
            'decided_at' => ['decided_at' => now()->addDay()->toIso8601String()],
        ];
        foreach ($cases as $field => $bad) {
            $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", $bad + $this->payload)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['decision', 'customer_name', 'channel']);

        $this->assertDatabaseCount('quote_decisions', 0);
    }

    public function test_the_decision_needs_a_timezone_and_cannot_predate_the_issue(): void
    {
        $quote = $this->issued();
        $quote->forceFill(['issued_at' => now()])->save();
        $admin = User::factory()->create();

        foreach (['2026-09-24T10:00', 'yesterday', '2026-09-24'] as $naive) {
            $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", ['decided_at' => $naive] + $this->payload)
                ->assertUnprocessable()->assertJsonValidationErrors('decided_at');
        }
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", ['decided_at' => now()->subDay()->toIso8601String()] + $this->payload)
            ->assertUnprocessable()->assertJsonValidationErrors('decided_at');

        $this->assertDatabaseCount('quote_decisions', 0);
        $this->assertSame(WorkOrderStatus::AwaitingApproval, $quote->workOrder->fresh()->status);
    }

    public function test_a_rejected_version_cannot_be_edited_reissued_or_replaced_by_a_new_first_draft(): void
    {
        $quote = $this->issued();
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", ['decision' => 'rejected'] + $this->payload)->assertOk();

        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => []])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/issue")->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/quotes")->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertConflict();

        $this->assertDatabaseCount('quotes', 1);
        $this->assertDatabaseCount('quote_decisions', 1);
    }

    public function test_the_detail_shows_the_decision_of_the_current_quote(): void
    {
        $quote = $this->issued();
        $admin = User::factory()->create();

        $this->actingAs($admin)->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertJsonPath('data.current_quote.decision', null);
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertOk();
        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$quote->work_order_id}")
            ->assertJsonPath('data.current_quote.decision.customer_name', 'Maria Silva')
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_a_decision_is_history_in_the_database(): void
    {
        $quote = $this->issued();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/quotes/{$quote->id}/decision", $this->payload)->assertOk();

        foreach ([
            fn () => DB::table('quote_decisions')->where('quote_id', $quote->id)->update(['decision' => 'rejected']),
            fn () => DB::table('quote_decisions')->where('quote_id', $quote->id)->delete(),
            fn () => DB::table('quote_decisions')->insert(['quote_id' => $quote->id, 'decision' => 'approved', 'customer_name' => 'x', 'channel' => 'phone', 'decided_at' => now(), 'recorded_by' => User::factory()->create()->id]),
            fn () => DB::table('quote_decisions')->insert(['quote_id' => Quote::factory()->create()->id, 'decision' => 'approved', 'customer_name' => 'x', 'channel' => 'pombo', 'decided_at' => now(), 'recorded_by' => User::factory()->create()->id]),
        ] as $sql) {
            try {
                DB::transaction($sql);
                $this->fail('O banco aceitou uma alteração inválida na decisão.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $quote->id, 'decision' => 'approved']);
    }
}
