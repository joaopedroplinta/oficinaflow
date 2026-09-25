<?php

namespace Tests\Feature;

use App\Enums\QuoteStatus;
use App\Models\Part;
use App\Models\Quote;
use App\Models\WorkOrder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// O banco recusa estados inválidos mesmo que uma Action falhe em impedi-los.
class QuoteConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /** Savepoint por violação: sem ele o Postgres aborta a transação inteira do teste. */
    private function assertRejected(Closure $sql): void
    {
        try {
            DB::transaction($sql);
            $this->fail('O banco aceitou uma linha inválida.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function item(Quote $quote, array $override = []): array
    {
        return $override + ['quote_id' => $quote->id, 'kind' => 'service', 'part_id' => null, 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 100, 'position' => 0];
    }

    public function test_only_one_non_superseded_quote_per_order(): void
    {
        $quote = Quote::factory()->create();

        $this->assertRejected(fn () => Quote::factory()->create(['work_order_id' => $quote->work_order_id, 'version' => 2]));
        // Depois de substituída, a próxima versão entra.
        $quote->forceFill(['status' => QuoteStatus::Superseded])->save();
        Quote::factory()->create(['work_order_id' => $quote->work_order_id, 'version' => 2]);
        $this->assertDatabaseCount('quotes', 2);
    }

    public function test_version_is_unique_per_order(): void
    {
        $quote = Quote::factory()->status(QuoteStatus::Superseded)->create();

        $this->assertRejected(fn () => Quote::factory()->status(QuoteStatus::Superseded)->create(['work_order_id' => $quote->work_order_id, 'version' => 1]));
    }

    public function test_current_quote_must_belong_to_the_same_order(): void
    {
        $order = WorkOrder::factory()->create();
        $foreign = Quote::factory()->create();

        $this->assertRejected(fn () => DB::table('work_orders')->where('id', $order->id)->update(['current_quote_id' => $foreign->id]));

        DB::table('work_orders')->where('id', $foreign->work_order_id)->update(['current_quote_id' => $foreign->id]);
        $this->assertDatabaseHas('work_orders', ['id' => $foreign->work_order_id, 'current_quote_id' => $foreign->id]);
    }

    public function test_quote_status_and_total_are_checked(): void
    {
        $this->assertRejected(fn () => Quote::factory()->create()->newQuery()->update(['status' => 'approved']));
        $this->assertRejected(fn () => Quote::factory()->create()->newQuery()->update(['total_cents' => -1]));
    }

    public function test_item_values_are_checked(): void
    {
        $quote = Quote::factory()->create();

        $this->assertRejected(fn () => DB::table('quote_items')->insert($this->item($quote, ['quantity' => 0])));
        $this->assertRejected(fn () => DB::table('quote_items')->insert($this->item($quote, ['unit_price_cents' => -1])));
        $this->assertRejected(fn () => DB::table('quote_items')->insert($this->item($quote, ['kind' => 'gift'])));
        DB::table('quote_items')->insert($this->item($quote, ['unit_price_cents' => 0]));
        $this->assertDatabaseCount('quote_items', 1);
    }

    public function test_part_items_need_a_part_and_service_items_must_not_have_one(): void
    {
        $quote = Quote::factory()->create();
        $part = Part::factory()->create();

        $this->assertRejected(fn () => DB::table('quote_items')->insert($this->item($quote, ['kind' => 'part'])));
        $this->assertRejected(fn () => DB::table('quote_items')->insert($this->item($quote, ['kind' => 'service', 'part_id' => $part->id])));
    }

    public function test_the_same_part_cannot_repeat_in_a_quote(): void
    {
        $quote = Quote::factory()->create();
        $part = Part::factory()->create();
        $row = $this->item($quote, ['kind' => 'part', 'part_id' => $part->id]);

        DB::table('quote_items')->insert($row);
        $this->assertRejected(fn () => DB::table('quote_items')->insert($row));
    }

    public function test_stock_balances_are_checked(): void
    {
        $this->assertRejected(fn () => Part::factory()->create(['on_hand' => -1]));
        $this->assertRejected(fn () => Part::factory()->create(['on_hand' => 1, 'reserved_quantity' => 2]));
        $this->assertRejected(fn () => Part::factory()->create(['sale_price_cents' => -1]));
        $this->assertRejected(fn () => Part::factory()->create(['sale_price_cents' => 100000001]));
    }
}
