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

class QuoteDraftTest extends TestCase
{
    use RefreshDatabase;

    private function diagnosing(): WorkOrder
    {
        return WorkOrder::factory()->status(WorkOrderStatus::Diagnosing)->create();
    }

    private function draftFor(WorkOrder $order, int $version = 1): Quote
    {
        $quote = Quote::factory()->create(['work_order_id' => $order->id, 'version' => $version]);
        $order->forceFill(['current_quote_id' => $quote->id])->save();

        return $quote;
    }

    public function test_admin_creates_the_first_draft_and_it_becomes_the_current_quote(): void
    {
        $order = $this->diagnosing();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$order->id}/quotes")
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.status_label', 'Rascunho')
            ->assertJsonPath('data.total_cents', 0)
            ->assertJsonPath('data.items', []);

        $quote = Quote::sole();
        $this->assertSame($quote->id, $order->fresh()->current_quote_id);
    }

    public function test_technician_cannot_create_a_draft(): void
    {
        $order = $this->diagnosing();

        $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$order->id}/quotes")->assertForbidden();

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_guest_gets_401(): void
    {
        $order = $this->diagnosing();

        $this->postJson("/api/v1/work-orders/{$order->id}/quotes")->assertUnauthorized();
        $this->putJson('/api/v1/quotes/1/draft', [])->assertUnauthorized();
    }

    public function test_draft_needs_the_order_in_diagnosing(): void
    {
        $order = WorkOrder::factory()->create();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$order->id}/quotes")
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_a_second_first_draft_is_a_conflict(): void
    {
        $order = $this->diagnosing();
        $admin = User::factory()->create();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/quotes")->assertCreated();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$order->id}/quotes")
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quotes', 1);
    }

    public function test_saving_items_copies_the_catalog_snapshot_and_computes_the_total_on_the_server(): void
    {
        $order = $this->diagnosing();
        $quote = $this->draftFor($order);
        $part = Part::factory()->create(['name' => 'Tela 15"', 'sale_price_cents' => 25000]);

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", [
            'notes' => 'Garantia de 90 dias',
            'total_cents' => 1, // ignorado
            'items' => [
                // descrição e preço enviados para peça de catálogo são ignorados
                ['kind' => 'part', 'part_id' => $part->id, 'quantity' => 2, 'description' => 'Fake', 'unit_price_cents' => 1],
                ['kind' => 'service', 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 8000],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.total_cents', 58000)
            ->assertJsonPath('data.notes', 'Garantia de 90 dias')
            ->assertJsonPath('data.items.0.description', 'Tela 15"')
            ->assertJsonPath('data.items.0.unit_price_cents', 25000)
            ->assertJsonPath('data.items.0.line_total_cents', 50000)
            ->assertJsonPath('data.items.0.kind_label', 'Peça')
            ->assertJsonPath('data.items.1.kind_label', 'Serviço')
            ->assertJsonPath('data.items.1.part_id', null);

        // O catálogo muda depois; o snapshot do orçamento não.
        $part->update(['name' => 'Outro nome', 'sale_price_cents' => 99999]);
        $this->assertDatabaseHas('quote_items', ['quote_id' => $quote->id, 'description' => 'Tela 15"', 'unit_price_cents' => 25000]);
        $this->assertSame(58000, $quote->fresh()->total_cents);
    }

    public function test_saving_again_replaces_the_items(): void
    {
        $quote = $this->draftFor($this->diagnosing());
        $admin = User::factory()->create();
        $service = fn (string $d) => ['kind' => 'service', 'description' => $d, 'quantity' => 1, 'unit_price_cents' => 1000];

        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => [$service('A'), $service('B')]])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => [$service('C')]])
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.total_cents', 1000);

        $this->assertSame(['C'], $quote->items()->pluck('description')->all());
    }

    public function test_the_same_part_cannot_repeat_in_the_version(): void
    {
        $quote = $this->draftFor($this->diagnosing());
        $part = Part::factory()->create();

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => [
            ['kind' => 'part', 'part_id' => $part->id, 'quantity' => 1],
            ['kind' => 'part', 'part_id' => $part->id, 'quantity' => 2],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.part_id');

        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_several_service_items_are_fine(): void
    {
        $quote = $this->draftFor($this->diagnosing());

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => [
            ['kind' => 'service', 'description' => 'A', 'quantity' => 1, 'unit_price_cents' => 100],
            ['kind' => 'service', 'description' => 'B', 'quantity' => 1, 'unit_price_cents' => 100],
        ]])->assertOk();
    }

    public function test_invalid_items_are_rejected_and_nothing_is_saved(): void
    {
        $quote = $this->draftFor($this->diagnosing());
        $inactive = Part::factory()->create(['active' => false]);
        $admin = User::factory()->create();

        $cases = [
            'items.0.part_id' => [['kind' => 'part', 'quantity' => 1]],
            'items.0.description' => [['kind' => 'service', 'quantity' => 1, 'unit_price_cents' => 100]],
            'items.0.unit_price_cents' => [['kind' => 'service', 'description' => 'x', 'quantity' => 1]],
            'items.0.quantity' => [['kind' => 'service', 'description' => 'x', 'quantity' => 0, 'unit_price_cents' => 100]],
            'items.0.unit_price_cents ' => [['kind' => 'service', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => -1]],
            'items.0.kind' => [['kind' => 'other', 'quantity' => 1]],
            'items.0.part_id  ' => [['kind' => 'part', 'part_id' => $inactive->id, 'quantity' => 1]],
            'items.0.part_id   ' => [['kind' => 'part', 'part_id' => 999999, 'quantity' => 1]],
        ];

        foreach ($cases as $field => $items) {
            $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => $items])
                ->assertUnprocessable()->assertJsonValidationErrors(trim($field));
        }
        $this->actingAs($admin)->putJson("/api/v1/quotes/{$quote->id}/draft", [])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_a_service_item_cannot_carry_a_part(): void
    {
        $quote = $this->draftFor($this->diagnosing());
        $part = Part::factory()->create();

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => [
            ['kind' => 'service', 'part_id' => $part->id, 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 100],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.part_id');
    }

    public function test_the_draft_cannot_change_once_the_order_left_diagnosing(): void
    {
        $order = $this->diagnosing();
        $quote = $this->draftFor($order);
        $order->forceFill(['status' => WorkOrderStatus::AwaitingReturn, 'cancellation_reason' => 'Cancelado no teste.'])->save();

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", [
            'items' => [['kind' => 'service', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 100]],
        ])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_technician_cannot_edit_the_draft(): void
    {
        $quote = $this->draftFor($this->diagnosing());

        $this->actingAs(User::factory()->technician()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", ['items' => []])->assertForbidden();
    }

    public function test_an_issued_version_cannot_be_edited(): void
    {
        $quote = $this->draftFor($this->diagnosing());
        $quote->forceFill(['status' => QuoteStatus::Issued])->save();

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$quote->id}/draft", [
            'items' => [['kind' => 'service', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 100]],
        ])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_a_superseded_version_answers_quote_superseded(): void
    {
        $order = $this->diagnosing();
        $old = Quote::factory()->status(QuoteStatus::Superseded)->create(['work_order_id' => $order->id]);
        $this->draftFor($order, 2);

        $this->actingAs(User::factory()->create())->putJson("/api/v1/quotes/{$old->id}/draft", ['items' => []])
            ->assertConflict()->assertJsonPath('code', 'QUOTE_SUPERSEDED');
    }

    public function test_the_order_detail_carries_the_current_quote(): void
    {
        $order = $this->diagnosing();
        $quote = $this->draftFor($order);

        $this->actingAs(User::factory()->technician()->create())->getJson("/api/v1/work-orders/{$order->id}")
            ->assertOk()->assertJsonPath('data.current_quote.id', $quote->id)->assertJsonPath('data.current_quote.status', 'draft');
    }
}
