<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteItemKind;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\Part;
use App\Models\Quote;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class SaveQuoteDraft
{
    /**
     * @param  array{notes?: string|null, items: list<array{kind: string, part_id?: int|null, description?: string|null, quantity: int, unit_price_cents?: int|null}>}  $data
     */
    public function handle(Quote $quote, array $data): Quote
    {
        return DB::transaction(function () use ($quote, $data) {
            // Ordem primeiro; só depois o estado da versão é lido (o model da rota pode estar velho).
            $order = WorkOrder::whereKey($quote->work_order_id)->lockForUpdate()->firstOrFail();
            $quote = Quote::whereKey($quote->id)->firstOrFail();

            if ($order->current_quote_id !== $quote->id) {
                throw new QuoteSuperseded;
            }
            if (! $quote->status->isEditable()) {
                throw new InvalidTransition('Esta versão já foi emitida e não pode mais ser alterada. Crie uma revisão do orçamento.');
            }

            // Peças em ordem crescente de ID (protocolo de locks). Lock compartilhado: o preço copiado não muda no meio.
            $partIds = collect($data['items'])->pluck('part_id')->filter()->unique()->sort()->values();
            $parts = Part::whereIn('id', $partIds)->orderBy('id')->sharedLock()->get()->keyBy('id');

            $rows = [];
            $total = 0;
            foreach (array_values($data['items']) as $position => $item) {
                $part = $item['kind'] === QuoteItemKind::Part->value ? $parts[$item['part_id']] : null;
                $description = $part?->name ?? $item['description'];
                $price = $part?->sale_price_cents ?? $item['unit_price_cents'];

                $rows[] = [
                    'kind' => $item['kind'],
                    'part_id' => $part?->id,
                    'description' => $description,
                    'quantity' => $item['quantity'],
                    'unit_price_cents' => $price,
                    'position' => $position,
                ];
                $total += $item['quantity'] * $price;
            }

            $quote->items()->delete();
            $quote->items()->createMany($rows);
            $quote->notes = $data['notes'] ?? null;
            $quote->total_cents = $total;
            $quote->save();

            return $quote->load('items');
        });
    }
}
