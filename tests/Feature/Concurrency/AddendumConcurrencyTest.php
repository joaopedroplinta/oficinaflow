<?php

namespace Tests\Feature\Concurrency;

use App\Actions\Quotes\CreateAddendum;
use App\Actions\Quotes\IssueAddendum;
use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\SaveQuoteDraft;
use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;

/** Adendo sob concorrência real: um em aberto por vez; a reserva só enxerga o adendo depois da aprovação. */
class AddendumConcurrencyTest extends ConcurrencyTestCase
{
    use BuildsApprovedOrders;

    public function test_two_simultaneous_addenda_create_only_one(): void
    {
        $base = $this->approvedOrder([], WorkOrderStatus::InProgress);
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(CreateAddendum::class)->handle(WorkOrder::find($base->work_order_id), $admin),
            'addendum', ['order_id' => $base->work_order_id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(1, Quote::where('work_order_id', $base->work_order_id)->where('kind', 'addendum')->count());
    }

    public function test_reserving_while_the_addendum_decision_is_in_flight_waits_and_then_reserves_the_approved_items(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $base = $this->approvedOrder([], WorkOrderStatus::InProgress);
        $admin = User::factory()->create();
        $order = WorkOrder::find($base->work_order_id);
        $addendum = app(CreateAddendum::class)->handle($order, $admin);
        app(SaveQuoteDraft::class)->handle($addendum, ['items' => [['kind' => 'part', 'part_id' => $part->id, 'quantity' => 2]]]);
        app(IssueAddendum::class)->handle($addendum, $admin);

        $result = $this->whileInFlight(
            fn () => app(RecordQuoteDecision::class)->handle($addendum, ['decision' => 'approved', 'customer_name' => 'Maria', 'channel' => 'phone'], $admin),
            'reserve', ['order_id' => $base->work_order_id, 'quote_id' => $base->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result);
        $this->assertSame(2, PartReservation::sole()->quantity, 'a reserva esperou o lock e viu o adendo já aprovado');
        $this->assertSame([5, 2], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'addendum_approved')->count());
        $this->assertStockInvariant();
    }

    public function test_two_simultaneous_decisions_on_the_same_addendum_record_one(): void
    {
        $base = $this->approvedOrder([], WorkOrderStatus::InProgress);
        $admin = User::factory()->create();
        $addendum = app(CreateAddendum::class)->handle(WorkOrder::find($base->work_order_id), $admin);
        app(SaveQuoteDraft::class)->handle($addendum, ['items' => [['kind' => 'service', 'description' => 'Extra', 'quantity' => 1, 'unit_price_cents' => 1000]]]);
        app(IssueAddendum::class)->handle($addendum, $admin);

        $result = $this->whileInFlight(
            fn () => app(RecordQuoteDecision::class)->handle($addendum, ['decision' => 'approved', 'customer_name' => 'Maria', 'channel' => 'phone'], $admin),
            'decide', ['quote_id' => $addendum->id, 'actor_id' => $admin->id, 'data' => ['decision' => 'rejected', 'customer_name' => 'João', 'channel' => 'phone']],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(['approved'], DB::table('quote_decisions')->where('quote_id', $addendum->id)->pluck('decision')->all());
        $this->assertSame(1, DB::table('work_order_events')->whereIn('type', ['addendum_approved', 'addendum_rejected'])->count());
    }
}
