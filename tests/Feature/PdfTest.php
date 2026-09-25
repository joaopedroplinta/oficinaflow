<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Documents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

class PdfTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    public function test_the_quote_pdf_downloads_for_both_roles_with_a_readable_file_name(): void
    {
        $part = Part::factory()->create(['name' => 'Fonte 500W', 'sale_price_cents' => 22000]);
        $quote = $this->approvedOrder([[$part, 2]]);

        foreach ([User::factory()->create(), User::factory()->technician()->create()] as $user) {
            $response = $this->actingAs($user)->get("/api/v1/quotes/{$quote->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertStringContainsString("orcamento-os-{$quote->workOrder->number}-v1.pdf", $response->headers->get('content-disposition'));
        }
    }

    public function test_the_order_pdf_downloads(): void
    {
        $order = WorkOrder::factory()->create();

        $response = $this->actingAs(User::factory()->technician()->create())->get("/api/v1/work-orders/{$order->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('ordem-de-servico-', $response->headers->get('content-disposition'));
    }

    public function test_guest_gets_401_even_on_a_plain_browser_navigation_and_unknown_records_404(): void
    {
        $order = WorkOrder::factory()->create();
        $quote = Quote::factory()->create();

        // Sem `Accept: application/json` (é assim que o navegador abre o link do PDF): 401, não 500 nem redirecionamento.
        $this->get("/api/v1/work-orders/{$order->id}/pdf")->assertUnauthorized();
        $this->get("/api/v1/quotes/{$quote->id}/pdf")->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->get('/api/v1/work-orders/999999/pdf')->assertNotFound();
        $this->get('/api/v1/quotes/999999/pdf')->assertNotFound();
    }

    public function test_the_pdf_is_generated_on_demand_without_persisting_a_file(): void
    {
        Storage::fake('local');
        $quote = $this->approvedOrder([]);

        $this->actingAs(User::factory()->create())->get("/api/v1/quotes/{$quote->id}/pdf")->assertOk();
        $this->get("/api/v1/work-orders/{$quote->work_order_id}/pdf")->assertOk();

        $this->assertSame([], Storage::disk('local')->allFiles(), 'o PDF é gerado sob demanda, sem persistir arquivo');
    }

    public function test_the_quote_document_has_customer_items_totals_and_the_decision_in_portuguese(): void
    {
        $part = Part::factory()->create(['name' => 'Fonte 500W', 'sale_price_cents' => 22000]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $quote->workOrder->customer->update(['name' => 'Maria Silva']);

        $html = Documents::quote($quote->fresh());

        foreach (['Orçamento — OS #'.$quote->workOrder->number.' · versão 1', 'Maria Silva', 'Fonte 500W', 'R$ 220,00', 'R$ 440,00', 'Mão de obra', 'R$ 490,00', 'Decisão do cliente', 'Aprovado', 'Registrada para', 'Emitido'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('Rascunho.', $html);
    }

    public function test_drafts_and_superseded_versions_are_marked_and_undecided_ones_say_so(): void
    {
        $order = WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create(['diagnosis' => 'x']);
        $draft = Quote::factory()->create(['work_order_id' => $order->id]);
        $old = Quote::factory()->status(QuoteStatus::Superseded)->create(['work_order_id' => $order->id, 'version' => 0 + 2]);

        $draft->update(['notes' => 'Prazo de 5 dias úteis']);
        $this->assertStringContainsString('Prazo de 5 dias úteis', Documents::quote($draft->fresh()));
        $this->assertStringContainsString('não vale como proposta', Documents::quote($draft->fresh()));
        $this->assertStringContainsString('Ainda sem decisão registrada.', Documents::quote($draft->fresh()));
        $this->assertStringContainsString('Versão substituída.', Documents::quote($old->fresh()));
    }

    public function test_the_order_document_uses_the_intake_snapshot_and_tells_the_whole_story(): void
    {
        $device = Device::factory()->for(Customer::factory()->create(['name' => 'João Pereira']))->create(['brand' => 'Dell', 'model' => 'Inspiron 15']);
        $admin = User::factory()->create(['name' => 'Ana']);
        $orderId = $this->actingAs($admin)->postJson('/api/v1/work-orders', [
            'customer_id' => $device->customer_id, 'device_id' => $device->id,
            'reported_problem' => 'Não liga', 'intake_condition' => 'Arranhões', 'accessories' => 'Carregador',
        ])->json('data.id');
        $device->update(['model' => 'Modelo trocado depois']);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$orderId}/cancel", ['reason' => 'Cliente desistiu do reparo'])->assertOk();

        $html = Documents::workOrder(WorkOrder::find($orderId));

        foreach (['Ordem de serviço #', 'João Pereira', 'Inspiron 15', 'Não liga', 'Arranhões', 'Carregador', 'Ainda sem orçamento.', 'Motivo do cancelamento', 'Cliente desistiu do reparo', 'Ordem aberta', 'Serviço cancelado', 'Aguardando devolução', 'Ana'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('Modelo trocado depois', $html, 'o documento usa o snapshot da entrada, não o cadastro atual');
    }

    public function test_user_provided_text_is_escaped(): void
    {
        $order = WorkOrder::factory()->create(['reported_problem' => '<script>alert(1)</script> & <b>negrito</b>']);
        $order->customer->update(['name' => '<img src=x onerror=alert(1)>']);

        $html = Documents::workOrder($order->fresh());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;negrito&lt;/b&gt;', $html);
    }
}
