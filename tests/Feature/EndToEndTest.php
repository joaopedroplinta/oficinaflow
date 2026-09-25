<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PLANO §9: o fluxo inteiro, da abertura à entrega, só pela API, conferindo estado final e estoque. */
class EndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whole_flow_from_intake_to_delivery_with_a_stock_shortage_along_the_way(): void
    {
        $admin = User::factory()->create(['name' => 'Ana']);
        $tech = User::factory()->technician()->create(['name' => 'Tiago']);
        $customer = Customer::factory()->create(['name' => 'Maria Silva']);
        $device = Device::factory()->for($customer)->create(['category' => 'Notebook', 'brand' => 'Dell', 'model' => 'Inspiron 15', 'serial_number' => 'ABC123']);

        // Catálogo e estoque: a fonte entra com saldo 1 (o orçamento vai precisar de 2) e o cabo com 10.
        $fonte = $this->actingAs($admin)->postJson('/api/v1/parts', ['sku' => 'FONTE-500', 'name' => 'Fonte 500W', 'sale_price_cents' => 22000])->assertCreated()->json('data.id');
        $cabo = $this->actingAs($admin)->postJson('/api/v1/parts', ['sku' => 'CABO-1', 'name' => 'Cabo de força', 'sale_price_cents' => 1500])->assertCreated()->json('data.id');
        $this->actingAs($admin)->postJson("/api/v1/parts/{$fonte}/receipts", ['quantity' => 1, 'reason' => 'Compra NF 1'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/parts/{$cabo}/receipts", ['quantity' => 10])->assertOk();

        // 1. Abertura (atendimento) com snapshot do equipamento.
        $orderId = $this->actingAs($admin)->postJson('/api/v1/work-orders', [
            'customer_id' => $customer->id, 'device_id' => $device->id,
            'reported_problem' => 'Não liga', 'intake_condition' => 'Arranhões na tampa', 'accessories' => 'Carregador',
        ])->assertCreated()->assertJsonPath('data.status', 'received')->json('data.id');
        $device->update(['model' => 'Modelo alterado depois']); // o histórico da ordem não muda

        // 2. Diagnóstico (técnico).
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$orderId}/start-diagnosis")->assertOk()->assertJsonPath('data.status', 'diagnosing');
        $this->actingAs($tech)->patchJson("/api/v1/work-orders/{$orderId}/diagnosis", ['diagnosis' => 'Fonte queimada e cabo com mau contato', 'assigned_technician_id' => $tech->id])->assertOk();

        // 3. Orçamento (atendimento): 2 fontes, 1 cabo e mão de obra; o servidor calcula o total.
        $quoteId = $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/quotes")->assertCreated()->json('data.id');
        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quoteId}/draft", ['notes' => 'Garantia de 90 dias', 'items' => [
            ['kind' => 'part', 'part_id' => $fonte, 'quantity' => 2],
            ['kind' => 'part', 'part_id' => $cabo, 'quantity' => 1],
            ['kind' => 'service', 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 8000],
        ]])->assertOk()->assertJsonPath('data.total_cents', 2 * 22000 + 1500 + 8000);
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quoteId}/issue")->assertOk()->assertJsonPath('data.status', 'issued');

        // 4. Aprovação do cliente, registrada pelo atendimento, presa à versão.
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$quoteId}/decision", ['decision' => 'approved', 'customer_name' => 'Maria Silva', 'channel' => 'whatsapp'])
            ->assertOk()->assertJsonPath('data.decision.decision', 'approved');
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$orderId}")->assertJsonPath('data.status', 'approved');

        // 5. Exceção relevante: falta fonte. Nada é reservado (nem o cabo), a ordem continua aprovada e "aguardando peças".
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/reservations", ['quote_id' => $quoteId])
            ->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('details.shortages.0.sku', 'FONTE-500');
        $this->assertSame(0, PartReservation::count());
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$orderId}")->assertJsonPath('data.status', 'approved')->assertJsonCount(2, 'data.pending');
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$orderId}/start-repair", ['quote_id' => $quoteId])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        // Entra a fonte que faltava e a reserva passa (tudo ou nada).
        $this->actingAs($admin)->postJson("/api/v1/parts/{$fonte}/receipts", ['quantity' => 1, 'reason' => 'Compra NF 2'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/reservations", ['quote_id' => $quoteId])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([2, 2], [Part::find($fonte)->on_hand, Part::find($fonte)->reserved_quantity]);
        $this->actingAs($tech)->getJson("/api/v1/work-orders/{$orderId}")->assertJsonPath('data.pending', []);

        // 6. Reparo (técnico): iniciar consome as peças reservadas; concluir registra o trabalho.
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$orderId}/start-repair", ['quote_id' => $quoteId])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$orderId}/complete-repair", ['work_performed' => 'Troca da fonte e do cabo; teste de carga de 1h.'])
            ->assertOk()->assertJsonPath('data.status', 'ready_for_pickup');

        // 7. Entrega (atendimento).
        $this->actingAs($tech)->postJson("/api/v1/work-orders/{$orderId}/deliver")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/deliver")->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.status_label', 'Entregue');

        // Estado final da ordem.
        $order = WorkOrder::find($orderId);
        $this->assertSame('delivered', $order->status->value);
        $this->assertSame('Modelo alterado depois', $device->fresh()->model);
        $this->assertSame('Inspiron 15', $order->device_model, 'o snapshot da entrada continua o da entrada');
        $this->assertNotNull($order->started_at);
        $this->assertNotNull($order->ready_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame($quoteId, $order->current_quote_id);

        // Estoque final: fonte 2−2, cabo 10−1; nada reservado; consumo registrado e ligado às reservas.
        $this->assertSame([0, 0], [Part::find($fonte)->on_hand, Part::find($fonte)->reserved_quantity]);
        $this->assertSame([9, 0], [Part::find($cabo)->on_hand, Part::find($cabo)->reserved_quantity]);
        $this->assertSame(2, PartReservation::where('status', 'consumed')->count());
        $this->assertSame(0, PartReservation::where('status', 'active')->count());
        $this->assertSame(2, InventoryMovement::where('kind', 'consumption')->whereNotNull('reservation_id')->count());
        foreach ([$fonte, $cabo] as $partId) {
            $this->assertSame(
                (int) InventoryMovement::where('part_id', $partId)->where('kind', 'receipt')->sum('quantity') - (int) InventoryMovement::where('part_id', $partId)->where('kind', 'consumption')->sum('quantity'),
                Part::find($partId)->on_hand,
                'o livro-razão explica o saldo',
            );
        }

        // A linha do tempo conta a história inteira, com autor.
        $timeline = $this->actingAs($tech)->getJson("/api/v1/work-orders/{$orderId}/events")->assertOk();
        $this->assertSame(
            ['opened', 'diagnosis_started', 'diagnosis_recorded', 'quote_issued', 'quote_approved', 'parts_reserved', 'repair_started', 'repair_completed', 'delivered'],
            collect($timeline->json('data'))->pluck('type')->all(),
        );
        $this->assertSame(['Ana', 'Tiago', 'Tiago', 'Ana', 'Ana', 'Ana', 'Tiago', 'Tiago', 'Ana'], collect($timeline->json('data'))->pluck('actor.name')->all());

        // Depois de entregue, nada mais se mexe.
        foreach (['start-diagnosis', 'deliver'] as $action) {
            $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/{$action}")->assertConflict();
        }
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/cancel", ['reason' => 'Tarde demais'])->assertConflict();
    }
}
