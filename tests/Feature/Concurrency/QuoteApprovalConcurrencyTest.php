<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\ReviseQuote;
use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/** Grupo 2 do PLANO §9 sob concorrência real: aprovação presa à versão do orçamento. */
class QuoteApprovalConcurrencyTest extends ConcurrencyTestCase
{
    private array $approval = ['decision' => 'approved', 'customer_name' => 'Maria Silva', 'channel' => 'phone'];

    /** Ordem aguardando aprovação com a V1 emitida. */
    private function awaitingApproval(): array
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::AwaitingApproval)->create(['diagnosis' => 'Fonte']);
        $v1 = Quote::factory()->create(['work_order_id' => $order->id, 'total_cents' => 8000]);
        $v1->items()->create(['kind' => 'service', 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 8000, 'position' => 0]);
        $v1->forceFill(['status' => QuoteStatus::Issued, 'issued_at' => now()->subHour()])->save();
        $order->forceFill(['current_quote_id' => $v1->id])->save();

        return [$order, $v1, User::factory()->create()];
    }

    private function assertConsistent(WorkOrder $order): void
    {
        $order = $order->fresh();
        $live = Quote::where('work_order_id', $order->id)->where('status', '!=', QuoteStatus::Superseded->value)->get();

        $this->assertCount(1, $live, 'Deve haver exatamente uma versão não substituída.');
        $this->assertSame($live->first()->id, $order->current_quote_id);
    }

    public function test_two_revisions_of_the_same_version_create_only_one_new_version(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(ReviseQuote::class)->handle($order, $v1->id, $admin),
            'revise', ['order_id' => $order->id, 'quote_id' => $v1->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'QUOTE_SUPERSEDED'], $result);
        $this->assertSame(2, Quote::where('work_order_id', $order->id)->count());
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'quote_revised')->count());
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status);
        $this->assertConsistent($order);
    }

    public function test_approving_a_version_that_is_being_revised_is_refused_and_records_nothing(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(ReviseQuote::class)->handle($order, $v1->id, $admin),
            'decide', ['quote_id' => $v1->id, 'data' => $this->approval, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'QUOTE_SUPERSEDED'], $result);
        $this->assertSame(0, DB::table('quote_decisions')->count());
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status);
        $this->assertConsistent($order);
    }

    public function test_revising_a_version_that_is_being_approved_keeps_the_approval_in_the_history(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(RecordQuoteDecision::class)->handle($v1, $this->approval, $admin),
            'revise', ['order_id' => $order->id, 'quote_id' => $v1->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $v2 = Quote::where('work_order_id', $order->id)->where('version', 2)->sole();
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $v1->id, 'decision' => 'approved']);
        $this->assertDatabaseMissing('quote_decisions', ['quote_id' => $v2->id]);
        $this->assertSame(QuoteStatus::Superseded, $v1->fresh()->status);
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->fresh()->status, 'A V2 exige nova aprovação.');
        $this->assertSame(['quote_approved', 'quote_revised'], DB::table('work_order_events')->orderBy('id')->pluck('type')->all());
        $this->assertConsistent($order);
    }

    public function test_approving_and_rejecting_the_same_version_at_once_records_one_decision(): void
    {
        [$order, $v1, $admin] = $this->awaitingApproval();

        $result = $this->whileInFlight(
            fn () => app(RecordQuoteDecision::class)->handle($v1, $this->approval, $admin),
            'decide', ['quote_id' => $v1->id, 'data' => ['decision' => 'rejected'] + $this->approval, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(1, DB::table('quote_decisions')->count());
        $this->assertDatabaseHas('quote_decisions', ['quote_id' => $v1->id, 'decision' => 'approved']);
        $this->assertSame(WorkOrderStatus::Approved, $order->fresh()->status);
        $this->assertSame(1, DB::table('work_order_events')->count());
        $this->assertConsistent($order);
    }
}
