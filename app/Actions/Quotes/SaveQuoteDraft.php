<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteItemKind;
use App\Enums\WorkOrderStatus;
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

            if ($quote->isAddendum()) {
                // Adendo: editável enquanto rascunho, com o reparo em andamento e a base ainda atual.
                if ($order->current_quote_id !== $quote->base_quote_id) {
                    throw new QuoteSuperseded;
                }
                if ($order->status !== WorkOrderStatus::InProgress) {
                    throw InvalidTransition::from($order->status, 'alterar o adendo');
                }
                if (! $quote->status->isEditable()) {
                    throw new InvalidTransition('Este adendo já foi emitido e não pode mais ser alterado.');
                }
            } else {
                if ($order->current_quote_id !== $quote->id) {
                    throw new QuoteSuperseded;
                }
                if ($order->status !== WorkOrderStatus::Diagnosing) {
                    throw InvalidTransition::from($order->status, 'alterar o orçamento');
                }
                if (! $quote->status->isEditable()) {
                    throw new InvalidTransition('Esta versão já foi emitida e não pode mais ser alterada. Crie uma revisão do orçamento.');
                }
            }

            // Peças em ordem crescente de ID (protocolo de locks). FOR SHARE é intencional: só impede o preço
            // de mudar no meio da cópia, sem serializar rascunhos que compartilham uma peça.
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
