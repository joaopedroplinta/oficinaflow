<?php

namespace Tests\Concerns;

use App\Enums\QuoteStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

trait BuildsApprovedOrders
{
    /**
     * Ordem cuja versão atual está emitida e aprovada, com os itens de peça dados ([part, quantidade]) e,
     * opcionalmente, um serviço. Grava direto no banco: serve com ou sem transação de teste.
     *
     * @param  array<int, array{0: Part, 1: int}>  $lines
     */
    private function approvedOrder(array $lines, WorkOrderStatus $status = WorkOrderStatus::Approved, bool $withService = true): Quote
    {
        $order = WorkOrder::factory()->status($status)->create(['diagnosis' => 'x']);
        $quote = Quote::factory()->create(['work_order_id' => $order->id]);
        $position = 0;
        foreach ($lines as [$part, $qty]) {
            $quote->items()->create(['kind' => 'part', 'part_id' => $part->id, 'description' => $part->name, 'quantity' => $qty, 'unit_price_cents' => $part->sale_price_cents, 'position' => $position++]);
        }
        if ($withService) {
            $quote->items()->create(['kind' => 'service', 'description' => 'Mão de obra', 'quantity' => 1, 'unit_price_cents' => 5000, 'position' => $position]);
        }
        $quote->forceFill(['status' => QuoteStatus::Issued, 'issued_at' => now()->subHour()])->save();
        $order->forceFill(['current_quote_id' => $quote->id])->save();
        DB::table('quote_decisions')->insert([
            'quote_id' => $quote->id, 'decision' => 'approved', 'customer_name' => 'Maria', 'channel' => 'phone',
            'decided_at' => now(), 'recorded_by' => User::factory()->create()->id,
        ]);

        return $quote;
    }

    /** reserved_quantity == soma das reservas ativas, e nenhum saldo negativo (invariante do PLANO §4). */
    private function assertStockInvariant(): void
    {
        foreach (Part::all() as $part) {
            $active = (int) PartReservation::where('part_id', $part->id)->where('status', 'active')->sum('quantity');
            $this->assertSame($active, $part->reserved_quantity, "reserved_quantity de {$part->sku} não bate com as reservas ativas");
            $this->assertGreaterThanOrEqual(0, $part->on_hand);
            $this->assertLessThanOrEqual($part->on_hand, $part->reserved_quantity);
        }
    }
}
