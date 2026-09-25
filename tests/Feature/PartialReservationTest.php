<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Support\Documents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

/** Reserva parcial (PLANO §13.3): opt-in; várias reservas por item; o que falta fica pendente. */
class PartialReservationTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private function reserve(Quote $seen, bool $partial = true, ?User $user = null)
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->postJson("/api/v1/work-orders/{$seen->work_order_id}/reservations", ['quote_id' => $seen->id] + ($partial ? ['partial' => true] : []));
    }

    private function receive(Part $part, int $quantity): void
    {
        $this->actingAs(User::factory()->create())->postJson("/api/v1/parts/{$part->id}/receipts", ['quantity' => $quantity])->assertOk();
    }

    public function test_partial_reserves_what_is_available_and_the_rest_stays_pending(): void
    {
        $part = Part::factory()->create(['sku' => 'FONTE', 'name' => 'Fonte', 'on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);

        $this->reserve($quote)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', 3)->assertJsonPath('data.0.status', 'active');

        $this->assertSame([3, 3], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame(WorkOrderStatus::Approved, $quote->workOrder->fresh()->status);
        $event = $quote->workOrder->events()->where('type', 'parts_reserved')->sole();
        $this->assertTrue($event->metadata['partial']);
        $this->assertEquals([['part_id' => $part->id, 'quantity' => 3]], $event->metadata['reservations']);
        $this->assertEquals([['part_id' => $part->id, 'quantity' => 2]], $event->metadata['pending']);

        $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertOk()
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.needed', 2)->assertJsonPath('data.pending.0.reserved', 3)->assertJsonPath('data.pending.0.quantity', 5)->assertJsonPath('data.pending.0.available', 0)
            ->assertJsonCount(1, 'data.reservations');
        $this->getJson('/api/v1/work-orders?awaiting_parts=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.awaiting_parts', true);
        $this->assertStockInvariant();
    }

    public function test_the_default_is_still_all_or_nothing(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);

        $this->reserve($quote, partial: false)->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.shortages.0.needed', 5)->assertJsonPath('details.shortages.0.available', 3);

        $this->assertDatabaseCount('part_reservations', 0);
        $this->assertSame(0, $part->fresh()->reserved_quantity);
        $this->assertStockInvariant();
    }

    public function test_reserving_again_after_new_stock_takes_only_what_was_missing_and_then_repeats_harmlessly(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();

        $this->receive($part, 4);
        $this->reserve($quote)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.quantity', 3)->assertJsonPath('data.1.quantity', 2);

        $this->assertSame([7, 5], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame(2, PartReservation::count(), 'duas reservas para o mesmo item');
        $this->assertSame(2, $quote->workOrder->events()->where('type', 'parts_reserved')->count());

        // Tudo reservado: repetir (com ou sem partial) devolve o que existe e não grava nada.
        $this->reserve($quote)->assertOk()->assertJsonCount(2, 'data');
        $this->reserve($quote, partial: false)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, PartReservation::count());
        $this->assertSame(2, $quote->workOrder->events()->where('type', 'parts_reserved')->count());
        $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->assertJsonCount(0, 'data.pending');
        $this->getJson('/api/v1/work-orders?awaiting_parts=1')->assertJsonCount(0, 'data');
        $this->assertStockInvariant();
    }

    public function test_repeating_a_partial_request_with_no_new_stock_is_a_409_and_changes_nothing(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();

        $this->reserve($quote)->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK')->assertJsonPath('details.shortages.0.available', 0);

        $this->assertSame(1, PartReservation::count());
        $this->assertSame(3, $part->fresh()->reserved_quantity);
        $this->assertSame(1, $quote->workOrder->events()->where('type', 'parts_reserved')->count());
        $this->assertStockInvariant();
    }

    public function test_an_all_or_nothing_retry_after_a_partial_reservation_needs_only_the_rest(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();

        $this->reserve($quote, partial: false)->assertConflict()->assertJsonPath('details.shortages.0.needed', 2)->assertJsonPath('details.shortages.0.available', 0);
        $this->receive($part, 2);
        $this->reserve($quote, partial: false)->assertOk();

        $this->assertSame(5, $part->fresh()->reserved_quantity);
        $this->assertStockInvariant();
    }

    public function test_partial_with_several_items_fills_what_it_can_and_touches_each_part_once(): void
    {
        $plenty = Part::factory()->create(['name' => 'Cabo', 'on_hand' => 10]);
        $short = Part::factory()->create(['name' => 'Fonte', 'on_hand' => 1]);
        $none = Part::factory()->create(['name' => 'Tela', 'on_hand' => 0]);
        $quote = $this->approvedOrder([[$plenty, 2], [$short, 4], [$none, 1]]);

        $this->reserve($quote)->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame([2, 1, 0], [$plenty->fresh()->reserved_quantity, $short->fresh()->reserved_quantity, $none->fresh()->reserved_quantity]);
        $pending = $this->getJson("/api/v1/work-orders/{$quote->work_order_id}")->json('data.pending');
        $this->assertEqualsCanonicalizing([['Fonte', 3], ['Tela', 1]], collect($pending)->map(fn ($p) => [$p['name'], $p['needed']])->all());
        $this->assertStockInvariant();
    }

    public function test_partial_with_nothing_available_is_a_409_and_records_nothing(): void
    {
        $part = Part::factory()->create(['on_hand' => 0]);
        $quote = $this->approvedOrder([[$part, 2]]);

        $this->reserve($quote)->assertConflict()->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseCount('part_reservations', 0);
        $this->assertSame(0, $quote->workOrder->events()->where('type', 'parts_reserved')->count());
    }

    public function test_the_repair_cannot_start_until_everything_is_reserved_and_then_consumes_every_reservation(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);
        $admin = User::factory()->create();
        $this->reserve($quote, true, $admin)->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id])
            ->assertConflict()->assertJsonPath('code', 'INVALID_TRANSITION')->assertJsonPath('details.missing.0.quantity', 2);
        $this->assertSame([3, 3], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity], 'nada foi consumido');

        $this->receive($part, 2);
        $this->reserve($quote, true, $admin)->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/start-repair", ['quote_id' => $quote->id])->assertOk();

        $this->assertSame([0, 0], [$part->fresh()->on_hand, $part->fresh()->reserved_quantity]);
        $this->assertSame(2, PartReservation::where('status', 'consumed')->count());
        $this->assertSame([3, 2], DB::table('inventory_movements')->where('kind', 'consumption')->orderBy('id')->pluck('quantity')->all());
        $this->assertStockInvariant();
    }

    public function test_revising_the_quote_releases_every_partial_reservation(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);
        $admin = User::factory()->create();
        $this->reserve($quote, true, $admin)->assertOk();
        $this->receive($part, 1);
        $this->reserve($quote, true, $admin)->assertOk();

        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/quote-revisions", ['quote_id' => $quote->id])->assertCreated();

        $this->assertSame(0, $part->fresh()->reserved_quantity);
        $this->assertSame(2, PartReservation::where('status', 'released')->count());
        $this->assertStockInvariant();
    }

    public function test_the_order_sheet_shows_how_much_of_each_part_is_reserved(): void
    {
        $part = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$part, 5]]);
        $this->reserve($quote)->assertOk();

        $html = Documents::workOrder($quote->workOrder->fresh());

        $this->assertStringContainsString('Reservada 3 de 5', $html);
    }

    public function test_partial_must_be_a_boolean_and_only_the_admin_can_reserve(): void
    {
        $quote = $this->approvedOrder([[Part::factory()->create(), 1]]);

        $this->actingAs(User::factory()->create())->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id, 'partial' => 'talvez'])->assertUnprocessable()->assertJsonValidationErrors('partial');
        $this->reserve($quote, true, User::factory()->technician()->create())->assertForbidden();
    }
}
