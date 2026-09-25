<?php

namespace Tests\Feature;

use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovedOrders;
use Tests\TestCase;

class ReleaseReservationsTest extends TestCase
{
    use BuildsApprovedOrders;
    use RefreshDatabase;

    private function reserve($quote, User $admin): void
    {
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/reservations", ['quote_id' => $quote->id])->assertOk();
    }

    private function revise($quote, User $admin)
    {
        return $this->actingAs($admin)->postJson("/api/v1/work-orders/{$quote->work_order_id}/quote-revisions", ['quote_id' => $quote->id]);
    }

    public function test_revising_releases_the_reservations_without_touching_the_physical_balance(): void
    {
        $a = Part::factory()->create(['on_hand' => 5]);
        $b = Part::factory()->create(['on_hand' => 3]);
        $quote = $this->approvedOrder([[$a, 2], [$b, 3]]);
        $admin = User::factory()->create();
        $this->reserve($quote, $admin);
        $this->assertSame([2, 3], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity]);

        $this->revise($quote, $admin)->assertCreated();

        $this->assertSame([0, 0], [$a->fresh()->reserved_quantity, $b->fresh()->reserved_quantity]);
        $this->assertSame([5, 3], [$a->fresh()->on_hand, $b->fresh()->on_hand], 'liberar não mexe no saldo físico');
        $this->assertSame(2, PartReservation::where('status', 'released')->whereNotNull('released_at')->count());
        $this->assertSame(0, PartReservation::where('status', 'active')->count());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertStockInvariant();
    }

    public function test_the_revision_event_records_what_was_released(): void
    {
        $part = Part::factory()->create(['on_hand' => 5]);
        $quote = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();
        $this->reserve($quote, $admin);
        $this->revise($quote, $admin)->assertCreated();

        $event = \DB::table('work_order_events')->where('type', 'quote_revised')->first();
        $this->assertSame([['part_id' => $part->id, 'quantity' => 2]], json_decode($event->metadata, true)['released_reservations']);
    }

    public function test_the_released_stock_is_available_again_for_another_order(): void
    {
        $part = Part::factory()->create(['on_hand' => 1]);
        $first = $this->approvedOrder([[$part, 1]]);
        $second = $this->approvedOrder([[$part, 1]]);
        $admin = User::factory()->create();
        $this->reserve($first, $admin);
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$second->work_order_id}/reservations", ['quote_id' => $second->id])->assertConflict();

        $this->revise($first, $admin)->assertCreated();

        $this->reserve($second, $admin);
        $this->assertStockInvariant();
    }

    public function test_only_the_revised_orders_reservations_are_released(): void
    {
        $part = Part::factory()->create(['on_hand' => 10]);
        $mine = $this->approvedOrder([[$part, 2]]);
        $other = $this->approvedOrder([[$part, 3]]);
        $admin = User::factory()->create();
        $this->reserve($mine, $admin);
        $this->reserve($other, $admin);

        $this->revise($mine, $admin)->assertCreated();

        $this->assertSame(3, $part->fresh()->reserved_quantity);
        $this->assertSame('active', PartReservation::whereHas('quoteItem', fn ($q) => $q->where('quote_id', $other->id))->value('status')->value);
        $this->assertStockInvariant();
    }

    public function test_after_a_revision_the_new_version_reserves_from_scratch_and_the_old_one_stays_released(): void
    {
        $part = Part::factory()->create(['on_hand' => 2]);
        $v1 = $this->approvedOrder([[$part, 2]]);
        $admin = User::factory()->create();
        $this->reserve($v1, $admin);
        $v2Id = $this->revise($v1, $admin)->assertCreated()->json('data.id');

        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v2Id}/issue")->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/quotes/{$v2Id}/decision", ['decision' => 'approved', 'customer_name' => 'Maria', 'channel' => 'phone'])->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/work-orders/{$v1->work_order_id}/reservations", ['quote_id' => $v2Id])->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(1, PartReservation::where('status', 'released')->count());
        $this->assertSame(1, PartReservation::where('status', 'active')->count());
        $this->assertSame(2, $part->fresh()->reserved_quantity);
        $this->assertStockInvariant();
    }

    public function test_revising_without_reservations_still_works(): void
    {
        $quote = $this->approvedOrder([]);

        $this->revise($quote, User::factory()->create())->assertCreated();

        $this->assertDatabaseCount('part_reservations', 0);
    }
}
