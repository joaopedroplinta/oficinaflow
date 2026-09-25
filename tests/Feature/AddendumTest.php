<?php

namespace Tests\Feature;

use App\Actions\Inventory\ReleaseReservations;
use App\Enums\WorkOrderStatus;
use App\Mail\QuoteMail;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Documents;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Adendo ao orçamento durante o reparo (PLANO §13.3): a versão aprovada continua imutável. */
class AddendumTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
    }

    /** Ordem em reparo, versão atual emitida e aprovada (só mão de obra: R$ 50,00). */
    private function inProgress(): Quote
    {
        return $this->approvedOrder([], WorkOrderStatus::InProgress);
    }

    private function create(Quote $base, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/addenda");
    }

    private function fill(int $addendumId, array $items)
    {
        return $this->actingAs($this->admin)->putJson("/api/v1/quotes/{$addendumId}/draft", ['items' => $items]);
    }

    private function issue(int $addendumId)
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/quotes/{$addendumId}/issue");
    }

    private function decide(int $addendumId, string $decision = 'approved')
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/quotes/{$addendumId}/decision", ['decision' => $decision, 'customer_name' => 'Maria', 'channel' => 'phone']);
    }

    /** Adendo com uma peça e um serviço, já emitido. */
    private function issuedAddendum(Quote $base, Part $part, int $quantity = 2): int
    {
        $id = $this->create($base)->assertCreated()->json('data.id');
        $this->fill($id, [['kind' => 'part', 'part_id' => $part->id, 'quantity' => $quantity], ['kind' => 'service', 'description' => 'Troca extra', 'quantity' => 1, 'unit_price_cents' => 3000]])->assertOk();
        $this->issue($id)->assertOk();

        return $id;
    }

    private function events(Quote $base, string $type)
    {
        return $base->workOrder->events()->where('type', $type)->get();
    }

    public function test_creating_an_addendum_keeps_the_approved_version_and_the_order_state(): void
    {
        $base = $this->inProgress();

        $this->create($base)->assertCreated()
            ->assertJsonPath('data.kind', 'addendum')->assertJsonPath('data.kind_label', 'Adendo')
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.base_quote_id', $base->id)->assertJsonPath('data.status', 'draft');

        $order = $base->workOrder->fresh();
        $this->assertSame(WorkOrderStatus::InProgress, $order->status);
        $this->assertSame($base->id, $order->current_quote_id, 'a versão atual não muda');
        $this->assertSame('issued', $base->fresh()->status->value);
        $this->assertSame(5000, $base->fresh()->total_cents);
    }

    public function test_only_while_in_progress_only_the_admin_and_one_open_addendum_at_a_time(): void
    {
        foreach ([WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::Delivered, WorkOrderStatus::AwaitingReturn] as $status) {
            $quote = $this->approvedOrder([], $status);
            $this->create($quote)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
            $this->assertSame(0, Quote::where('work_order_id', $quote->work_order_id)->where('kind', 'addendum')->count(), $status->value);
        }

        $base = $this->inProgress();
        $this->create($base, User::factory()->technician()->create())->assertForbidden();

        $first = $this->create($base)->assertCreated()->json('data.id');
        $this->create($base)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');          // rascunho aberto
        $this->fill($first, [['kind' => 'service', 'description' => 'Extra', 'quantity' => 1, 'unit_price_cents' => 1000]])->assertOk();
        $this->issue($first)->assertOk();
        $this->create($base)->assertConflict();                                                       // emitido, sem decisão
        $this->decide($first, 'rejected')->assertOk();
        $this->create($base)->assertCreated()->assertJsonPath('data.version', 2);                     // decidido: libera o próximo
    }

    public function test_the_draft_is_editable_until_issued_and_the_server_computes_the_total(): void
    {
        $part = Part::factory()->create(['name' => 'Cabo', 'sale_price_cents' => 1500, 'on_hand' => 9]);
        $base = $this->inProgress();
        $id = $this->create($base)->assertCreated()->json('data.id');

        $this->fill($id, [['kind' => 'part', 'part_id' => $part->id, 'quantity' => 2], ['kind' => 'service', 'description' => 'Extra', 'quantity' => 1, 'unit_price_cents' => 2000]])
            ->assertOk()->assertJsonPath('data.total_cents', 5000)->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.description', 'Cabo');
        $this->fill($id, [['kind' => 'service', 'description' => 'Só isto', 'quantity' => 1, 'unit_price_cents' => 700]])->assertOk()->assertJsonPath('data.total_cents', 700);

        $this->issue($id)->assertOk()->assertJsonPath('data.status', 'issued');
        $this->fill($id, [])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->issue($id)->assertConflict();
        $this->assertSame(700, Quote::find($id)->total_cents);
    }

    public function test_issuing_keeps_the_order_in_progress_records_the_event_and_needs_items(): void
    {
        $base = $this->inProgress();
        $id = $this->create($base)->assertCreated()->json('data.id');

        $this->issue($id)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->fill($id, [['kind' => 'service', 'description' => 'Extra', 'quantity' => 1, 'unit_price_cents' => 1000]])->assertOk();
        $this->issue($id)->assertOk();

        $this->assertSame(WorkOrderStatus::InProgress, $base->workOrder->fresh()->status);
        $event = $this->events($base, 'addendum_issued')->sole();
        $this->assertSame([$id, 1, 1000], [$event->metadata['quote_id'], $event->metadata['number'], $event->metadata['total_cents']]);
        $this->assertSame('in_progress', $event->to_status->value);
    }

    public function test_the_decision_does_not_move_the_order_and_only_an_approved_addendum_counts_in_the_total(): void
    {
        $part = Part::factory()->create(['sale_price_cents' => 1000, 'on_hand' => 9]);
        $base = $this->inProgress();
        $first = $this->issuedAddendum($base, $part);            // 2 × 10,00 + 30,00 = 50,00
        $this->decide($first, 'rejected')->assertOk()->assertJsonPath('data.decision.decision', 'rejected');
        $this->decide($first)->assertConflict();                  // uma decisão por adendo
        $second = $this->issuedAddendum($base, $part, 1);        // 10,00 + 30,00 = 40,00

        $this->getJson("/api/v1/work-orders/{$base->work_order_id}")->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.total_cents', 5000);
        $this->decide($second)->assertOk()->assertJsonPath('data.decision.decision', 'approved');

        $this->assertSame(WorkOrderStatus::InProgress, $base->workOrder->fresh()->status);
        $this->getJson("/api/v1/work-orders/{$base->work_order_id}")->assertJsonPath('data.total_cents', 9000)->assertJsonPath('data.current_quote.id', $base->id);
        $this->assertSame(['addendum_rejected', 'addendum_approved'], $base->workOrder->events()->whereIn('type', ['addendum_rejected', 'addendum_approved'])->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame('in_progress', $this->events($base, 'addendum_approved')->sole()->to_status->value);
    }

    public function test_the_decision_needs_an_issued_addendum(): void
    {
        $base = $this->inProgress();
        $id = $this->create($base)->assertCreated()->json('data.id');

        $this->decide($id)->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->assertDatabaseCount('quote_decisions', 1); // só a decisão da versão base
    }

    public function test_addendum_parts_are_reserved_and_consumed_only_after_approval(): void
    {
        $part = Part::factory()->create(['name' => 'Cabo', 'on_hand' => 5]);
        $base = $this->inProgress();
        $id = $this->issuedAddendum($base, $part, 2);
        $detail = fn () => $this->actingAs($this->admin)->getJson("/api/v1/work-orders/{$base->work_order_id}");
        $reserve = fn () => $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/reservations", ['quote_id' => $base->id]);

        // Emitido sem decisão: nada a reservar.
        $detail()->assertJsonCount(0, 'data.pending');
        $reserve()->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, $part->fresh()->reserved_quantity);
        $this->getJson('/api/v1/work-orders?awaiting_parts=1')->assertJsonCount(0, 'data');

        $this->decide($id)->assertOk();
        $detail()->assertJsonCount(1, 'data.pending')->assertJsonPath('data.pending.0.name', 'Cabo')->assertJsonPath('data.pending.0.needed', 2);
        $this->getJson('/api/v1/work-orders?awaiting_parts=1')->assertJsonCount(1, 'data');

        $reserve()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', 2);
        $this->assertSame([5, 2], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame($id, PartReservation::sole()->quoteItem->quote_id, 'a reserva é do item do adendo');
        $detail()->assertJsonCount(0, 'data.pending')->assertJsonPath('data.to_consume.0.quantity', 2)->assertJsonCount(1, 'data.reservations');

        $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$base->work_order_id}/consumptions", ['quote_id' => $base->id])
            ->assertOk()->assertJsonPath('data.0.status', 'consumed');
        $this->assertSame([3, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame(1, InventoryMovement::where('kind', 'consumption')->count());
        $this->assertStockInvariant();
    }

    public function test_a_rejected_or_draft_addendum_never_reserves_and_the_database_agrees(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $base = $this->inProgress();
        $rejected = $this->issuedAddendum($base, $part);
        $this->decide($rejected, 'rejected')->assertOk();
        $draft = $this->create($base)->assertCreated()->json('data.id');
        $this->fill($draft, [['kind' => 'part', 'part_id' => $part->id, 'quantity' => 1]])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/reservations", ['quote_id' => $base->id])->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('part_reservations', 0);

        // Direto no banco: item de adendo emitido mas rejeitado não aceita reserva.
        $item = Quote::find($rejected)->items()->where('kind', 'part')->first();
        try {
            DB::transaction(fn () => DB::table('part_reservations')->insert(['quote_item_id' => $item->id, 'part_id' => $part->id, 'quantity' => 1, 'status' => 'active', 'reserved_by' => $this->admin->id, 'reserved_at' => now()]));
            $this->fail('o banco aceitou reserva de adendo rejeitado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('addendum that is not approved', $e->getMessage());
        }
    }

    public function test_the_repair_cannot_be_completed_with_approved_addendum_parts_not_yet_consumed(): void
    {
        $part = Part::factory()->create(['name' => 'Cabo', 'on_hand' => 5]);
        $base = $this->inProgress();
        $id = $this->issuedAddendum($base, $part, 2);
        $this->decide($id)->assertOk();
        $complete = fn () => $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$base->work_order_id}/complete-repair", ['work_performed' => 'Feito']);

        $complete()->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION')->assertJsonPath('details.pending.0.quantity', 2);

        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/reservations", ['quote_id' => $base->id])->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/consumptions", ['quote_id' => $base->id])->assertOk();
        $complete()->assertOk()->assertJsonPath('data.status', 'ready_for_pickup');
    }

    public function test_an_open_addendum_blocks_completion_until_it_is_decided(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $base = $this->inProgress();
        $complete = fn () => $this->actingAs(User::factory()->technician()->create())->postJson("/api/v1/work-orders/{$base->work_order_id}/complete-repair", ['work_performed' => 'Feito']);
        $detail = fn () => $this->actingAs($this->admin)->getJson("/api/v1/work-orders/{$base->work_order_id}");

        $draft = $this->create($base)->assertCreated()->json('data.id');
        $detail()->assertJsonPath('data.has_open_addendum', true);
        $complete()->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');

        $this->fill($draft, [['kind' => 'part', 'part_id' => $part->id, 'quantity' => 1]])->assertOk();
        $this->issue($draft)->assertOk();
        $complete()->assertConflict();                          // emitido, sem decisão

        $this->decide($draft, 'rejected')->assertOk();
        $detail()->assertJsonPath('data.has_open_addendum', false);
        $complete()->assertOk();                                // rejeitado não bloqueia
        $this->assertSame(WorkOrderStatus::ReadyForPickup, $base->workOrder->fresh()->status);
    }

    public function test_the_same_part_in_the_version_and_in_an_addendum_shares_the_stock(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $base = $this->approvedOrder([[$part, 2]], WorkOrderStatus::InProgress);   // início parcial: P×2 ainda não reservado
        $id = $this->issuedAddendum($base, $part, 2);
        $this->decide($id)->assertOk();
        $reserve = fn (array $extra = []) => $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/reservations", ['quote_id' => $base->id] + $extra);

        // Os dois itens pedem 2 da mesma peça com 3 em estoque: tudo ou nada recusa (não estoura o CHECK).
        $reserve()->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('details.shortages.0.needed', 2)->assertJsonPath('details.shortages.0.available', 1);
        $this->assertSame(0, $part->fresh()->reserved_quantity);

        // Parcial: 2 para o primeiro item, 1 para o segundo.
        $reserve(['partial' => true])->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame([3, 3], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertEqualsCanonicalizing([2, 1], PartReservation::pluck('quantity')->all());
        $this->assertStockInvariant();
    }

    public function test_revising_is_refused_while_in_progress_and_cancelling_releases_the_addendum_reservations(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $base = $this->inProgress();
        $id = $this->issuedAddendum($base, $part, 2);
        $this->decide($id)->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/reservations", ['quote_id' => $base->id])->assertOk();

        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/quote-revisions", ['quote_id' => $base->id])->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION');
        $this->assertSame(2, $part->fresh()->reserved_quantity);

        // Nada consumido ainda: cancelar não pede decisão de estorno e libera a reserva do adendo.
        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/cancel", ['reason' => 'Desistência'])->assertOk()->assertJsonPath('data.status', 'awaiting_return');

        $this->assertSame([5, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame('released', PartReservation::sole()->status->value);
        $this->assertSame('issued', Quote::find($id)->status->value);
        $this->assertSame($base->id, $base->workOrder->fresh()->current_quote_id);
        $this->assertStockInvariant();
    }

    public function test_releasing_the_reservations_of_the_order_also_releases_the_addendum_ones(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $base = $this->inProgress();
        $id = $this->issuedAddendum($base, $part, 3);
        $this->decide($id)->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/work-orders/{$base->work_order_id}/reservations", ['quote_id' => $base->id])->assertOk();
        $this->assertSame(3, $part->fresh()->reserved_quantity);

        // É o que o cancelamento em reparo vai chamar (PLANO §13.3).
        $released = app(ReleaseReservations::class)->handle($base->workOrder);

        $this->assertCount(1, $released);
        $this->assertSame([5, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertStockInvariant();
    }

    public function test_quotes_are_listed_with_kind_and_the_base(): void
    {
        $base = $this->inProgress();
        $id = $this->create($base)->assertCreated()->json('data.id');

        $res = $this->actingAs($this->admin)->getJson("/api/v1/work-orders/{$base->work_order_id}/quotes")->assertOk()->assertJsonCount(2, 'data');
        $byKind = collect($res->json('data'))->keyBy('kind');
        $this->assertSame($id, $byKind['addendum']['id']);
        $this->assertSame($base->id, $byKind['addendum']['base_quote_id']);
        $this->assertNull($byKind['version']['base_quote_id']);
    }

    public function test_the_addendum_can_be_sent_to_the_customer_while_in_progress(): void
    {
        Mail::fake();
        $part = Part::factory()->create(['on_hand' => 5]);
        $base = $this->inProgress();
        $base->workOrder->customer->forceFill(['email' => 'maria@example.com', 'phone' => '(41) 99999-8888', 'name' => 'Maria'])->save();
        $id = $this->issuedAddendum($base, $part);

        $this->actingAs($this->admin)->postJson("/api/v1/quotes/{$id}/deliveries", ['channel' => 'email'])->assertCreated();
        Mail::assertSent(QuoteMail::class, fn (QuoteMail $m) => $m->quote->id === $id && str_contains($m->envelope()->subject, 'Adendo ao orçamento'));

        $url = $this->actingAs($this->admin)->postJson("/api/v1/quotes/{$id}/deliveries", ['channel' => 'whatsapp'])->assertCreated()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('adendo ao orçamento', $query['text']);

        // Depois da decisão não se envia mais; e a versão base (já decidida) também não.
        $this->decide($id)->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/quotes/{$id}/deliveries", ['channel' => 'email'])->assertConflict();
        $this->actingAs($this->admin)->postJson("/api/v1/quotes/{$base->id}/deliveries", ['channel' => 'email'])->assertConflict();
    }

    public function test_the_pdfs_show_the_addendum_and_the_order_total(): void
    {
        $part = Part::factory()->create(['name' => 'Cabo HDMI', 'sale_price_cents' => 1000, 'on_hand' => 5]);
        $base = $this->inProgress();
        $id = $this->issuedAddendum($base, $part, 2);
        $this->decide($id)->assertOk();

        $addendumHtml = Documents::quote(Quote::find($id));
        $this->assertStringContainsString('Adendo ao orçamento', $addendumHtml);
        $this->assertStringContainsString('Cabo HDMI', $addendumHtml);
        $this->assertStringContainsString('Aprovado', $addendumHtml);

        $sheet = Documents::workOrder($base->workOrder->fresh());
        $this->assertStringContainsString('Adendo 1', $sheet);
        $this->assertStringContainsString('Cabo HDMI', $sheet);
        $this->assertStringContainsString('R$ 100,00', $sheet, 'total = 50,00 da versão + 50,00 do adendo');

        $this->actingAs($this->admin)->get("/api/v1/quotes/{$id}/pdf")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=adendo-os-'.$base->workOrder->fresh()->number.'-1.pdf');
    }

    public function test_the_database_keeps_addenda_coherent_even_without_the_action(): void
    {
        $base = $this->inProgress();
        $other = $this->approvedOrder([], WorkOrderStatus::InProgress);
        $insert = fn (array $row) => DB::table('quotes')->insert($row + [
            'work_order_id' => $base->work_order_id, 'version' => 1, 'kind' => 'addendum', 'base_quote_id' => $base->id, 'status' => 'draft',
            'total_cents' => 0, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fails = function (string $why, callable $do, string $needle) {
            try {
                DB::transaction($do);
                $this->fail("deveria recusar: {$why}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), $why);
            }
        };

        $fails('adendo sem base', fn () => $insert(['base_quote_id' => null]), 'must be an issued version'); // o trigger vem antes do CHECK
        $fails('versão com base', fn () => $insert(['kind' => 'version', 'version' => 2]), 'quotes_base_check');
        $fails('tipo desconhecido', fn () => $insert(['kind' => 'other', 'base_quote_id' => null]), 'quotes_kind_check');
        $fails('adendo substituído', fn () => $insert(['status' => 'superseded']), 'quotes_base_check');
        $fails('base de outra ordem', fn () => $insert(['base_quote_id' => $other->id]), 'addenda only exist while the repair is in progress');
        $fails('outra versão não substituída na ordem', fn () => $insert(['kind' => 'version', 'base_quote_id' => null, 'version' => 2]), 'quotes_one_current_per_order');

        // Adendo sobre adendo, sobre rascunho ou com a ordem fora de reparo.
        $addendum = $this->create($base)->assertCreated()->json('data.id');
        $this->fill($addendum, [['kind' => 'service', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 100]])->assertOk();
        $this->issue($addendum)->assertOk();
        $fails('base que é adendo', fn () => $insert(['base_quote_id' => $addendum, 'version' => 2]), 'must be an issued version');
        WorkOrder::whereKey($base->work_order_id)->update(['status' => 'ready_for_pickup', 'ready_at' => now(), 'work_performed' => 'x']);
        $fails('ordem fora de reparo', fn () => $insert(['version' => 3]), 'only exist while the repair is in progress');
        WorkOrder::whereKey($base->work_order_id)->update(['status' => 'in_progress', 'ready_at' => null, 'work_performed' => null]);

        // Mesmo número de adendo na mesma ordem: recusado; tipo e base não mudam depois.
        $fails('número repetido', fn () => $insert(['version' => 1]), 'quotes_work_order_id_kind_version_unique');
        $fails('mudar o tipo', fn () => DB::table('quotes')->where('id', $addendum)->update(['kind' => 'version', 'base_quote_id' => null]), 'keeps its kind and base');
    }

    public function test_the_permission_of_the_generic_quote_routes_also_covers_addenda(): void
    {
        $base = $this->inProgress();
        $id = $this->create($base)->assertCreated()->json('data.id');
        $tech = User::factory()->technician()->create();

        $this->actingAs($tech)->putJson("/api/v1/quotes/{$id}/draft", ['items' => []])->assertForbidden();
        $this->actingAs($tech)->postJson("/api/v1/quotes/{$id}/issue")->assertForbidden();
        $this->actingAs($tech)->postJson("/api/v1/quotes/{$id}/decision", [])->assertForbidden();
        $this->assertNotNull(WorkOrder::find($base->work_order_id));
    }
}
