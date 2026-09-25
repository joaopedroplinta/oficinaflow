<?php

namespace Tests\Feature\Concurrency;

use App\Actions\WorkOrders\CompleteRepair;
use App\Actions\WorkOrders\OpenReturn;
use App\Enums\WorkOrderKind;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/** Transições de execução sob concorrência real: uma decisão por vez, sem evento duplicado. */
class WorkOrderTransitionConcurrencyTest extends ConcurrencyTestCase
{
    public function test_two_simultaneous_completions_record_one(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::InProgress)->create();
        $tech = User::factory()->technician()->create();

        $result = $this->whileInFlight(
            fn () => app(CompleteRepair::class)->handle($order, 'Serviço feito', $tech),
            'complete', ['order_id' => $order->id, 'actor_id' => $tech->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'INVALID_TRANSITION'], $result);
        $this->assertSame(WorkOrderStatus::ReadyForPickup, $order->fresh()->status);
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'repair_completed')->count());
    }

    public function test_delivering_while_the_completion_is_in_flight_waits_and_then_delivers(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::InProgress)->create();
        $tech = User::factory()->technician()->create();
        $admin = User::factory()->create();

        $result = $this->whileInFlight(
            fn () => app(CompleteRepair::class)->handle($order, 'Serviço feito', $tech),
            'deliver', ['order_id' => $order->id, 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'ok'], $result, 'a entrega esperou o lock e viu a ordem já pronta');
        $this->assertSame(WorkOrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(['repair_completed', 'delivered'], DB::table('work_order_events')->orderBy('id')->pluck('type')->all());
    }

    public function test_two_simultaneous_returns_of_the_same_delivered_order_open_only_one(): void
    {
        $origin = WorkOrder::factory()->status(WorkOrderStatus::Delivered)->create();
        $admin = User::factory()->create();
        $data = ['reported_problem' => 'A', 'intake_condition' => 'B', 'accessories' => 'C'];

        $result = $this->whileInFlight(
            fn () => app(OpenReturn::class)->handle($origin, WorkOrderKind::Warranty, $data, $admin),
            'return', ['order_id' => $origin->id, 'kind' => 'rework', 'actor_id' => $admin->id],
        );

        $this->assertSame(['result' => 'conflict', 'code' => 'RETURN_ALREADY_OPEN'], $result);
        $this->assertSame(1, DB::table('work_orders')->where('origin_order_id', $origin->id)->count());
        $this->assertSame(1, DB::table('work_order_events')->where('type', 'return_opened')->count());
        $this->assertSame(WorkOrderStatus::Delivered, $origin->fresh()->status);
    }
}
