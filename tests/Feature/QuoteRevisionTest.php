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

class QuoteRevisionTest extends TestCase
{
    use RefreshDatabase;

    private array $decision = ['customer_name' => 'Maria Silva', 'channel' => 'phone'];

    /** Ordem no estado dado, com V1 emitida (2 itens, uma peça de catálogo) e sem decisão. */
    private function withIssuedQuote(WorkOrderStatus $status = WorkOrderStatus::AwaitingApproval): Quote
    {
        $order = WorkOrder::factory()->status($status)->create(['diagnosis' => 'Fonte']);
        $quote = Quote::factory()->create(['work_order_id' => $order->id, 'total_cents' => 30000, 'notes' => 'Garantia 90 dias']);
        $part = Part::factory()->create(['name' => 'Fonte 500W', 'sale_price_cents' => 22000]);
        $quote->items()->createMany([
            ['kind' => 'part', 'part_id' => $part->id, 'description' => 'Fonte 500W', 'quantity' => 1, 'unit_price_cents' => 22000, 'position' => 0],
            ['kind' => 'service', 'part_id' => null, 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 8000, 'position' => 1],
        ]);
        // Itens entram enquanto é rascunho; depois de emitida, a versão congela.
        $quote->forceFill(['status' => QuoteStatus::Issued])->save();
        $order->forceFill(['current_quote_id' => $quote->id])->save();

        return $quote;
    }

    private function revise(Quote $seen, ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->postJson("/api/v1/work-orders/{$seen->work_order_id}/quote-revisions", ['quote_id' => $seen->id]);
    }

    public function test_revising_an_approved_quote_supersedes_it_and_asks_for_a_new_approval(): void
    {
        $v1 = $this->withIssuedQuote();
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v1->id}/decision", ['decision' => 'approved'] + $this->decision)->assertOk();

        $this->revise($v1, $admin)
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_cents', 30000)
            ->assertJsonPath('data.notes', 'Garantia 90 dias')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.description', 'Fonte 500W')
            ->assertJsonPath('data.decision', null);

        $order = $v1->workOrder->fresh();
        $v2 = Quote::where('work_order_id', $order->id)->where('version', 2)->sole();
        $this->assertSame($v2->id, $order->current_quote_id);
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->status);
        $this->assertSame(QuoteStatus::Superseded, $v1->fresh()->status);
        // A aprovação antiga permanece no histórico, presa à V1.
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $v1->id, 'decision' => 'approved']);
        $this->assertDatabaseMissing('quote_decisions', ['quote_id' => $v2->id]);
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'actor_id' => $admin->id, 'type' => 'quote_revised', 'from_status' => 'approved', 'to_status' => 'diagnosing']);
    }

    public function test_group_2_v1_approval_does_not_authorize_v2_and_actions_on_v1_conflict(): void
    {
        $v1 = $this->withIssuedQuote();
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v1->id}/decision", ['decision' => 'approved'] + $this->decision)->assertOk();
        $v2 = Quote::find($this->revise($v1, $admin)->json('data.id'));

        // V1 (superseded): nem editar, nem emitir, nem decidir de novo.
        $this->actingAs($admin)->putJson("/api/v1/quotes/{$v1->id}/draft", ['items' => []])->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v1->id}/issue")->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v1->id}/decision", ['decision' => 'approved'] + $this->decision)->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$v1->work_order_id}/quote-revisions", ['quote_id' => $v1->id])->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        // V2 não herda a aprovação: não dá para decidir antes de emitir, e a ordem não está aprovada.
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v2->id}/decision", ['decision' => 'approved'] + $this->decision)->assertConflict();
        $this->assertSame(WorkOrderStatus::Diagnosing, $v1->workOrder->fresh()->status);

        // Emitida, a V2 volta a exigir aprovação própria.
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v2->id}/issue")->assertOk();
        $this->assertSame(WorkOrderStatus::AwaitingApproval, $v1->workOrder->fresh()->status);
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v2->id}/decision", ['decision' => 'approved'] + $this->decision)->assertOk();
        $this->assertDatabaseCount('quote_decisions', 2);
    }

    public function test_revising_while_awaiting_approval_and_after_a_rejection(): void
    {
        $admin = User::factory()->create();

        $awaiting = $this->withIssuedQuote();
        $this->revise($awaiting, $admin)->assertCreated();
        $this->assertSame(WorkOrderStatus::Diagnosing, $awaiting->workOrder->fresh()->status);

        $rejected = $this->withIssuedQuote();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$rejected->id}/decision", ['decision' => 'rejected'] + $this->decision)->assertOk();
        $this->revise($rejected, $admin)->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $rejected->id, 'decision' => 'rejected']);
    }

    public function test_the_copy_is_a_snapshot_and_the_new_draft_is_editable(): void
    {
        $v1 = $this->withIssuedQuote();
        Part::query()->update(['sale_price_cents' => 99999, 'name' => 'Outro']);
        $admin = User::factory()->create();

        $v2Id = $this->revise($v1, $admin)->assertCreated()->assertJsonPath('data.items.0.unit_price_cents', 22000)->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/quotes/{$v2Id}/draft", ['items' => [
            ['kind' => 'service', 'description' => 'Só mão de obra', 'quantity' => 1, 'unit_price_cents' => 5000],
        ]])->assertOk()->assertJsonPath('data.total_cents', 5000);

        // V1 continua como estava.
        $this->assertSame(30000, $v1->fresh()->total_cents);
        $this->assertSame(2, $v1->items()->count());
    }

    public function test_only_one_version_is_current_after_a_revision(): void
    {
        $v1 = $this->withIssuedQuote();
        $this->revise($v1)->assertCreated();

        $this->assertSame(1, Quote::where('work_order_id', $v1->work_order_id)->where('status', '!=', 'superseded')->count());
    }

    public function test_a_stale_screen_gets_quote_superseded_and_nothing_changes(): void
    {
        $v1 = $this->withIssuedQuote();
        $admin = User::factory()->create();
        $this->revise($v1, $admin)->assertCreated();

        $this->revise($v1, $admin)->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');

        $this->assertDatabaseCount('quotes', 2);
        $this->assertDatabaseCount('work_order_events', 1);
    }

    public function test_cannot_revise_a_draft_or_an_order_without_a_quote(): void
    {
        $admin = User::factory()->create();

        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $draft = Quote::factory()->create(['work_order_id' => $order->id]);
        $order->forceFill(['current_quote_id' => $draft->id])->save();
        $this->revise($draft, $admin)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $bare = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$bare->id}/quote-revisions", ['quote_id' => 1])
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_cannot_revise_once_the_repair_started_or_the_order_ended(): void
    {
        foreach ([WorkOrderStatus::InProgress, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::Delivered, WorkOrderStatus::AwaitingReturn, WorkOrderStatus::Canceled] as $status) {
            $quote = $this->withIssuedQuote($status);

            $this->revise($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

            $this->assertSame($status, $quote->workOrder->fresh()->status);
            $this->assertSame(QuoteStatus::Issued, $quote->fresh()->status);
        }
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_technician_cannot_revise_and_guest_gets_401_and_quote_id_is_required(): void
    {
        $quote = $this->withIssuedQuote();

        $this->postJson("/api/v1/work-orders/{$quote->work_order_id}/quote-revisions", ['quote_id' => $quote->id])->assertUnauthorized();
        $this->revise($quote, User::factory()->technician()->create())->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/quote-revisions", [])
            ->assertUnprocessable()->assertJsonValidationErrors('quote_id');

        $this->assertDatabaseCount('quotes', 1);
    }
}
