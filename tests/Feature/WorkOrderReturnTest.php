<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Documents;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderReturnTest extends TestCase
{
    use RefreshDatabase;

    private array $body = ['kind' => 'warranty', 'reported_problem' => 'Voltou a não ligar', 'intake_condition' => 'Sem arranhões novos', 'accessories' => 'Carregador'];

    private function delivered(array $overrides = []): WorkOrder
    {
        // refresh: o `number` é gerado pelo banco.
        return WorkOrder::factory()->status(WorkOrderStatus::Delivered)->create($overrides)->refresh();
    }

    /** Entregue há 100 dias, com a garantia de 90 dias já vencida. */
    private function deliveredLongAgo(): WorkOrder
    {
        return $this->delivered([
            'received_at' => now()->subDays(103), 'started_at' => now()->subDays(102), 'ready_at' => now()->subDays(101),
            'delivered_at' => now()->subDays(100), 'warranty_until' => now()->subDays(10),
        ]);
    }

    private function open(WorkOrder $origin, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? User::factory()->create())->postJson("/api/v1/work-orders/{$origin->id}/returns", $body + $this->body);
    }

    public function test_a_warranty_return_opens_a_new_linked_order_and_leaves_the_original_untouched(): void
    {
        $origin = $this->delivered(['device_serial_number' => 'SN-1']);
        $before = $origin->fresh()->getAttributes();
        $admin = User::factory()->create();

        $res = $this->open($origin, [], $admin)->assertCreated()
            ->assertJsonPath('data.kind', 'warranty')->assertJsonPath('data.kind_label', 'Garantia')
            ->assertJsonPath('data.status', 'received')->assertJsonPath('data.origin.id', $origin->id)->assertJsonPath('data.origin.number', $origin->number)
            ->assertJsonPath('data.customer.id', $origin->customer_id)->assertJsonPath('data.device_id', $origin->device_id)
            ->assertJsonPath('data.device.serial_number', 'SN-1')->assertJsonPath('data.reported_problem', 'Voltou a não ligar');

        $return = WorkOrder::findOrFail($res->json('data.id'));
        $this->assertNotSame($origin->id, $return->id);
        $this->assertSame($origin->id, $return->origin_order_id);
        $this->assertSame($before, $origin->fresh()->getAttributes(), 'a ordem entregue é final: nada nela muda');

        $this->assertSame(['opened'], $return->events()->pluck('type')->map->value->all());
        $event = $origin->events()->where('type', 'return_opened')->sole();
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertEquals(['kind' => 'warranty', 'return_order_id' => $return->id, 'return_number' => $return->number], $event->metadata);
    }

    public function test_warranty_after_the_deadline_is_409_but_rework_has_no_deadline(): void
    {
        $origin = $this->deliveredLongAgo();

        $this->open($origin)->assertStatus(409)->assertJsonPath('code', 'WARRANTY_EXPIRED');
        $this->assertSame(0, WorkOrder::where('origin_order_id', $origin->id)->count());
        $this->assertSame(0, $origin->events()->where('type', 'return_opened')->count());

        $this->open($origin, ['kind' => 'rework'])->assertCreated()->assertJsonPath('data.kind', 'rework')->assertJsonPath('data.kind_label', 'Retrabalho');
    }

    public function test_only_a_delivered_order_can_have_a_return(): void
    {
        foreach ([WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved, WorkOrderStatus::InProgress, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::AwaitingReturn, WorkOrderStatus::Canceled] as $status) {
            $order = WorkOrder::factory()->status($status)->create();

            $this->open($order)->assertStatus(409)->assertJsonPath('code', 'INVALID_TRANSITION');
            $this->assertSame(0, WorkOrder::where('origin_order_id', $order->id)->count(), $status->value);
        }
    }

    public function test_one_open_return_per_original_until_it_ends(): void
    {
        $origin = $this->delivered();
        $first = $this->open($origin)->assertCreated()->json('data.id');

        $this->open($origin, ['kind' => 'rework'])->assertStatus(409)->assertJsonPath('code', 'RETURN_ALREADY_OPEN');
        $this->assertSame(1, WorkOrder::where('origin_order_id', $origin->id)->count());

        // Depois que o retorno termina (aqui, cancelado e devolvido), dá para abrir outro.
        WorkOrder::findOrFail($first)->forceFill(['status' => WorkOrderStatus::Canceled, 'cancellation_reason' => 'Sem defeito'])->save();
        $this->open($origin, ['kind' => 'rework'])->assertCreated();
        $this->assertSame(2, WorkOrder::where('origin_order_id', $origin->id)->count());
    }

    public function test_a_delivered_return_can_itself_be_returned_and_carries_its_own_warranty(): void
    {
        $origin = $this->delivered();
        $first = WorkOrder::findOrFail($this->open($origin)->json('data.id'));
        $first->forceFill([
            'status' => WorkOrderStatus::Delivered, 'started_at' => now()->addMinute(), 'ready_at' => now()->addMinutes(2), 'work_performed' => 'Refeito',
            'delivered_at' => now()->addMinutes(3), 'warranty_until' => now()->addDays(90),
        ])->save();

        $this->open($first)->assertCreated()->assertJsonPath('data.origin.id', $first->id);
    }

    public function test_validation_and_permissions(): void
    {
        $origin = $this->delivered();

        $this->open($origin, ['kind' => 'regular'])->assertUnprocessable()->assertJsonPath('errors.kind.0', 'Escolha entre garantia e retrabalho.');
        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$origin->id}/returns", ['kind' => 'warranty'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reported_problem', 'intake_condition', 'accessories']);
        $this->open($origin, [], User::factory()->technician()->create())->assertForbidden();

        $this->assertSame(0, WorkOrder::where('origin_order_id', $origin->id)->count());
    }

    public function test_the_return_order_follows_the_normal_flow(): void
    {
        $origin = $this->delivered();
        $id = $this->open($origin)->json('data.id');
        $tech = User::factory()->technician()->create();

        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$id}/start-diagnosis")->assertOk()->assertJsonPath('data.kind', 'warranty');
        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$id}/quotes")->assertCreated();
    }

    public function test_delivering_records_the_warranty_deadline_from_the_configured_days(): void
    {
        config(['oficinaflow.warranty_days' => 30]);
        $order = WorkOrder::factory()->status(WorkOrderStatus::ReadyForPickup)->create();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$order->id}/deliver")->assertOk();

        $order->refresh();
        $this->assertSame(30, (int) $order->delivered_at->diffInDays($order->warranty_until));
    }

    public function test_the_detail_links_both_orders_and_the_list_filters_by_kind(): void
    {
        $origin = $this->delivered();
        $admin = User::factory()->create();
        $return = $this->open($origin, [], $admin)->json('data');
        $regular = WorkOrder::factory()->create();

        $this->actingAs($admin)->getJson("/api/v1/work-orders/{$origin->id}")
            ->assertOk()->assertJsonPath('data.kind', 'regular')->assertJsonPath('data.origin', null)
            ->assertJsonPath('data.returns.0.id', $return['id'])->assertJsonPath('data.returns.0.kind_label', 'Garantia')->assertJsonPath('data.returns.0.status_label', 'Recebida')
            ->assertJsonPath('data.warranty_until', $origin->fresh()->warranty_until->toJSON());
        $this->getJson("/api/v1/work-orders/{$return['id']}")->assertJsonPath('data.origin.id', $origin->id)->assertJsonPath('data.returns', []);

        $this->getJson('/api/v1/work-orders?kind=warranty')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $return['id'])->assertJsonPath('data.0.origin.number', $origin->number);
        $this->getJson('/api/v1/work-orders?kind=regular')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/work-orders?kind=nope')->assertUnprocessable();
        $this->assertNotNull($regular->id);
    }

    public function test_the_order_sheet_pdf_shows_the_link_and_the_warranty_deadline(): void
    {
        $origin = $this->delivered();
        $admin = User::factory()->create();
        $return = $this->open($origin, [], $admin)->json('data.id');

        $html = Documents::workOrder(WorkOrder::findOrFail($return));
        $this->assertStringContainsString("Garantia da ordem #{$origin->number}", $html);
        $this->assertStringContainsString('até '.$origin->warranty_until->timezone('America/Sao_Paulo')->format('d/m/Y'), Documents::workOrder($origin->fresh()));
    }

    public function test_the_database_keeps_the_link_coherent_even_without_the_action(): void
    {
        $origin = $this->delivered();
        $other = WorkOrder::factory()->create();
        $insert = fn (array $row) => DB::table('work_orders')->insert($row + [
            'customer_id' => $origin->customer_id, 'device_id' => $origin->device_id, 'status' => 'received',
            'device_category' => 'x', 'device_brand' => 'x', 'device_model' => 'x', 'reported_problem' => 'x', 'intake_condition' => 'x', 'accessories' => 'x',
            'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fails = function (string $why, array $row, string $needle) use ($insert) {
            try {
                DB::transaction(fn () => $insert($row));
                $this->fail("deveria recusar: {$why}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $why);
            }
        };

        $fails('garantia sem origem', ['kind' => 'warranty'], 'work_orders_origin_check');
        $fails('origem em ordem regular', ['kind' => 'regular', 'origin_order_id' => $origin->id], 'work_orders_origin_check');
        $fails('tipo desconhecido', ['kind' => 'other'], 'work_orders_kind_check');
        $fails('origem de outro cliente/equipamento', ['kind' => 'warranty', 'origin_order_id' => $other->id], 'work_orders_origin_fk');
        $done = ['status' => 'delivered', 'received_at' => now()->subHours(3), 'started_at' => now()->subHours(2), 'ready_at' => now()->subHour(), 'delivered_at' => now(), 'work_performed' => 'x'];
        $fails('entregue sem prazo de garantia', $done, 'work_orders_warranty_check');
        $fails('prazo antes da entrega', $done + ['warranty_until' => now()->subDay()], 'work_orders_warranty_check');

        $insert(['kind' => 'warranty', 'origin_order_id' => $origin->id]);
        $fails('segundo retorno aberto', ['kind' => 'rework', 'origin_order_id' => $origin->id], 'work_orders_one_open_return_per_origin');
    }
}
