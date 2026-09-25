<?php

namespace Tests\Feature;

use App\Actions\Inventory\ReceivePart;
use App\Enums\WorkOrderStatus;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Grupo 4 do PLANO §9: cancelamento, devolução e transições. */
class WorkOrderCancellationTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private function stocked(int $onHand): Part
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        app(ReceivePart::class)->handle($part, $onHand, 'Estoque inicial', User::factory()->create());

        return $part->fresh();
    }

    private function reserve($quote, User $by): void
    {
        $this->actingAs($by)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertOk();
    }

    private function cancel(int $orderId, ?User $by = null, string $reason = 'Cliente desistiu do reparo')
    {
        return $this->actingAs($by ?? User::factory()->create())->postJson("/api/v1/work-orders/{$orderId}/cancel", ['reason' => $reason]);
    }

    public function test_cancelling_with_reserved_parts_releases_them_without_touching_the_physical_balance(): void
    {
        $a = $this->stocked(5);
        $b = $this->stocked(3);
        $quote = $this->approvedOrder([[$a, 2], [$b, 3]]);
        $admin = User::factory()->create();
        $this->reserve($quote, $admin);
        $movementsBefore = InventoryMovement::count();

        $this->cancel($quote->work_order_id, $admin, 'Cliente desistiu do reparo')
            ->assertOk()->assertJsonPath('data.status', 'awaiting_return')->assertJsonPath('data.status_label', 'Aguardando devolução')
            ->assertJsonPath('data.cancellation_reason', 'Cliente desistiu do reparo');

        $this->assertSame([5, 3], [$a->fresh()->on_hand, $b->fresh()->on_hand], 'cancelar não baixa o saldo físico');
        $this->assertSame([0, 0], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity]);
        $this->assertSame(2, PartReservation::where('status', 'released')->count());
        $this->assertSame($movementsBefore, InventoryMovement::count(), 'nenhum movimento de estoque');
        $event = DB::table('work_order_events')->where('type', 'canceled')->first();
        $this->assertSame('approved', $event->from_status);
        $this->assertSame('awaiting_return', $event->to_status);
        $this->assertSame(2, count(json_decode($event->metadata, true)['released_reservations']));
        $this->assertStockInvariant();
    }

    public function test_it_can_be_cancelled_from_every_state_before_the_repair(): void
    {
        foreach ([WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved] as $status) {
            $order = WorkOrder::factory()->status($status)->create();

            $this->cancel($order->id)->assertOk()->assertJsonPath('data.status', 'awaiting_return');

            $this->assertSame(WorkOrderStatus::AwaitingReturn, $order->fresh()->status);
            $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'type' => 'canceled', 'from_status' => $status->value, 'to_status' => 'awaiting_return']);
        }
    }

    public function test_it_cannot_be_cancelled_once_the_repair_started_and_the_consumed_parts_stay_consumed(): void
    {
        $part = $this->stocked(5);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();
        $this->reserve($quote, $admin);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id])->assertOk();

        $this->cancel($quote->work_order_id, $admin)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertSame(WorkOrderStatus::InProgress, $quote->workOrder->fresh()->status);
        $this->assertNull($quote->workOrder->fresh()->cancellation_reason);
        $this->assertSame(3, $part->fresh()->on_hand);
        $this->assertSame('consumed', PartReservation::sole()->status->value);
        $this->assertDatabaseMissing('work_order_events', ['type' => 'canceled']);
        $this->assertStockInvariant();
    }

    public function test_the_reason_is_required_and_only_the_admin_cancels(): void
    {
        $order = WorkOrder::factory()->create();
        $admin = User::factory()->create();

        $this->postJson("/api/v1/work-orders/{$order->id}/cancel", ['reason' => 'Motivo qualquer'])->assertUnauthorized();
        $this->cancel($order->id, User::factory()->technician()->create())->assertForbidden();
        foreach ([[], ['reason' => ''], ['reason' => '  '], ['reason' => 'ab'], ['reason' => str_repeat('x', 501)]] as $body) {
            $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/cancel", $body)->assertUnprocessable()->assertJsonValidationErrors('reason');
        }

        $this->assertSame(WorkOrderStatus::Received, $order->fresh()->status);
        $this->assertDatabaseCount('work_order_events', 0);
    }

    public function test_cancelling_is_not_delivering_the_device_stays_until_it_is_returned(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $admin = User::factory()->create();
        $this->cancel($order->id, $admin)->assertOk();

        // Depois de cancelar, o equipamento ainda está na assistência: nada de entregue, nada de data de entrega.
        $fresh = $order->fresh();
        $this->assertSame(WorkOrderStatus::AwaitingReturn, $fresh->status);
        $this->assertNull($fresh->delivered_at);

        $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")
            ->assertOk()->assertJsonPath('data.status', 'canceled')->assertJsonPath('data.status_label', 'Cancelada');

        $done = $order->fresh();
        $this->assertSame(WorkOrderStatus::Canceled, $done->status);
        $this->assertNull($done->delivered_at, 'devolver sem reparo não é entregar reparado');
        $this->assertDatabaseHas('work_order_events', ['work_order_id' => $order->id, 'type' => 'returned_without_repair', 'from_status' => 'awaiting_return', 'to_status' => 'canceled']);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
    }

    public function test_only_the_cancelled_orders_reservations_are_released_and_the_stock_is_free_again(): void
    {
        $part = $this->stocked(3);
        $mine = $this->approvedOrder([[$part, 2]]);
        $other = $this->approvedOrder([[$part, 1]]);
        $waiting = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();
        $this->reserve($mine, $admin);
        $this->reserve($other, $admin);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$waiting->work_order_id}/reservations", ['quote_id' => $waiting->id])->assertConflict();

        $this->cancel($mine->work_order_id, $admin)->assertOk();

        $this->assertSame(1, $part->fresh()->reserved_quantity, 'só a reserva da outra ordem continua');
        $this->reserve($waiting, $admin);
        $this->assertSame(3, $part->fresh()->reserved_quantity);
        $this->assertStockInvariant();
    }

    public function test_a_cancelled_order_keeps_its_quote_frozen(): void
    {
        $quote = $this->approvedOrder([]);
        $admin = User::factory()->create();
        $this->cancel($quote->work_order_id, $admin)->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/quote-revisions", ['quote_id' => $quote->id])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id])->assertConflict();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quote->id}/decision", ['decision' => 'approved', 'customer_name' => 'x', 'channel' => 'phone'])->assertConflict();
    }

    /**
     * Matriz do PLANO §5: cada ação só vale nos estados de origem dela; em qualquer outro, INVALID_TRANSITION,
     * sem mudar a ordem nem gravar evento. As ações que dependem de orçamento usam ordens com uma versão emitida e
     * aprovada, para que a recusa venha do estado e não da falta de orçamento.
     */
    public function test_every_action_is_rejected_outside_its_source_states(): void
    {
        // [método, caminho a partir da ordem, corpo (ou callable que recebe o orçamento), estados de origem, precisa de orçamento]
        $actions = [
            'start-diagnosis' => ['post', '/start-diagnosis', [], ['received'], false],
            'diagnosis' => ['patch', '/diagnosis', ['diagnosis' => 'Fonte'], ['diagnosing'], false],
            'first quote' => ['post', '/quotes', [], ['diagnosing'], false],
            'complete-repair' => ['post', '/complete-repair', ['work_performed' => 'Serviço feito'], ['in_progress'], false],
            'deliver' => ['post', '/deliver', [], ['ready_for_pickup', 'awaiting_return'], false],
            'cancel' => ['post', '/cancel', ['reason' => 'Desistência'], ['received', 'diagnosing', 'awaiting_approval', 'approved'], false],
            'reservations' => ['post', '/reservations', fn ($q) => ['quote_id' => $q->id], ['approved'], true],
            'quote-revisions' => ['post', '/quote-revisions', fn ($q) => ['quote_id' => $q->id], ['diagnosing', 'awaiting_approval', 'approved'], true],
            'start-repair' => ['post', '/start-repair', fn ($q) => ['quote_id' => $q->id], ['approved', 'in_progress'], true],
        ];
        $admin = User::factory()->create();
        $checked = 0;
        $expected = 0;

        foreach ($actions as [, , , $allowedFrom]) {
            $expected += count(WorkOrderStatus::cases()) - count($allowedFrom);
        }

        foreach (WorkOrderStatus::cases() as $status) {
            foreach ($actions as $name => [$method, $path, $body, $allowedFrom, $needsQuote]) {
                if (in_array($status->value, $allowedFrom, true)) {
                    continue;
                }
                $quote = $needsQuote ? $this->approvedOrder([], $status) : null;
                $order = $quote ? $quote->workOrder : WorkOrder::factory()->status($status)->create();

                $this->actingAs($admin)->json($method, "/api/v1/work-orders/{$order->id}{$path}", is_callable($body) ? $body($quote) : $body)
                    ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

                $this->assertSame($status, $order->fresh()->status, "{$name} mexeu numa ordem em {$status->value}");
                $checked++;
            }
        }

        $this->assertDatabaseCount('work_order_events', 0);
        $this->assertSame($expected, $checked, 'a matriz deve cobrir exatamente todas as combinações inválidas');
    }

    public function test_the_database_requires_a_reason_for_cancelled_states(): void
    {
        $order = WorkOrder::factory()->create();

        foreach (['awaiting_return', 'canceled'] as $status) {
            foreach ([null, '   '] as $reason) {
                try {
                    DB::transaction(fn () => DB::table('work_orders')->where('id', $order->id)->update(['status' => $status, 'cancellation_reason' => $reason]));
                    $this->fail("O banco aceitou {$status} sem motivo.");
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_the_timeline_tells_the_cancellation_and_the_return(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
        $admin = User::factory()->create();
        $this->cancel($order->id, $admin)->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertOk();

        $this->getJson("/api/v1/work-orders/{$order->id}/events")
            ->assertJsonPath('data.0.type_label', 'Serviço cancelado')->assertJsonPath('data.0.to_status_label', 'Aguardando devolução')
            ->assertJsonPath('data.1.type_label', 'Equipamento devolvido sem reparo')->assertJsonPath('data.1.to_status_label', 'Cancelada');
    }
}
