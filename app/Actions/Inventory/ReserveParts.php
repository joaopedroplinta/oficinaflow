<?php

namespace App\Actions\Inventory;

use App\Enums\QuoteDecisionType;
use App\Enums\ReservationStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReserveParts
{
    /**
     * Reserva as peças da versão aprovada. Padrão: tudo ou nada. Com `$partial`, cada item reserva
     * `min(o que falta, o que há disponível)` e o resto fica pendente (várias reservas por item; nova chamada
     * reserva o que faltava). Repetir depois de tudo reservado devolve as reservas existentes. Não muda o estado
     * da ordem (aprovação e disponibilidade são independentes).
     *
     * @return Collection<int, PartReservation>
     */
    public function handle(WorkOrder $order, int $seenQuoteId, User $actor, bool $partial = false): Collection
    {
        return DB::transaction(function () use ($order, $seenQuoteId, $actor, $partial) {
            // 1. Ordem primeiro; tudo o que vem depois lê estado já travado.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->current_quote_id === null) {
                throw new InvalidTransition('Esta ordem ainda não tem orçamento.');
            }
            if ($order->current_quote_id !== $seenQuoteId) {
                throw new QuoteSuperseded;
            }
            // Em reparo também: é como se reserva o que faltou depois de um início parcial.
            if (! in_array($order->status, [WorkOrderStatus::Approved, WorkOrderStatus::InProgress], true)) {
                throw InvalidTransition::from($order->status, 'reservar as peças');
            }

            $quote = Quote::whereKey($order->current_quote_id)->with('decision')->firstOrFail();
            if ($quote->decision?->decision !== QuoteDecisionType::Approved) {
                throw new InvalidTransition('O orçamento atual não tem aprovação registrada.');
            }

            // Itens da versão atual e dos adendos aprovados (adendo sem aprovação não reserva nada).
            $missing = $order->reservableItems()->filter(fn ($item) => $item->missingQuantity() > 0);

            // Idempotência: já reservado (por completo) devolve o que existe, sem tocar em nada.
            if ($missing->isEmpty()) {
                return $this->reservationsOf($order);
            }

            // 2. Peças em ordem crescente de ID (protocolo de locks; evita deadlock entre Actions).
            $parts = Part::whereIn('id', $missing->pluck('part_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $free = $parts->map(fn ($part) => $part->on_hand - $part->reserved_quantity);

            // 3. Quanto cabe em cada item. A mesma peça pode estar na versão atual e em um adendo aprovado: `$free` é
            // abatido item a item, senão os dois pediriam o mesmo saldo. Tudo ou nada: falta em qualquer item → nada é gravado.
            $allocation = [];
            $shortages = [];
            foreach ($missing as $item) {
                $take = $partial ? min($item->missingQuantity(), $free[$item->part_id]) : $item->missingQuantity();
                if ($take > $free[$item->part_id] || $take <= 0) {
                    $shortages[] = [
                        'part_id' => $item->part_id,
                        'sku' => $parts[$item->part_id]->sku,
                        'name' => $parts[$item->part_id]->name,
                        'needed' => $item->missingQuantity(),
                        'available' => $free[$item->part_id],
                    ];
                }
                $allocation[$item->id] = $take;
                $free[$item->part_id] -= max($take, 0);
            }
            // Parcial só falha se não houver nada para reservar; sem `partial`, qualquer falta recusa tudo.
            if ($shortages !== [] && (! $partial || array_sum($allocation) === 0)) {
                throw new InsufficientStock($shortages);
            }

            foreach ($missing as $item) {
                $take = $allocation[$item->id];
                if ($take <= 0) {
                    continue;
                }
                $part = $parts[$item->part_id];
                $part->reserved_quantity += $take;
                $part->save();

                $reservation = new PartReservation;
                $reservation->quote_item_id = $item->id;
                $reservation->part_id = $part->id;
                $reservation->quantity = $take;
                $reservation->status = ReservationStatus::Active;
                $reservation->reserved_by = $actor->id;
                $reservation->reserved_at = now();
                $reservation->save();
            }

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::PartsReserved,
                'from_status' => $order->status,
                'to_status' => $order->status,
                'metadata' => [
                    'quote_id' => $quote->id,
                    'partial' => $partial,
                    'reservations' => $missing->filter(fn ($item) => $allocation[$item->id] > 0)
                        ->map(fn ($item) => ['part_id' => $item->part_id, 'quantity' => $allocation[$item->id]])->values()->all(),
                    'pending' => $missing->map(fn ($item) => ['part_id' => $item->part_id, 'quantity' => $item->missingQuantity() - $allocation[$item->id]])
                        ->filter(fn ($p) => $p['quantity'] > 0)->values()->all(),
                ],
            ]);

            return $this->reservationsOf($order);
        });
    }

    /** @return Collection<int, PartReservation> as reservas em vigor (ativas e consumidas) da ordem, com a peça. */
    private function reservationsOf(WorkOrder $order): Collection
    {
        return PartReservation::whereHas('quoteItem.quote', fn ($q) => $q->where('work_order_id', $order->id))
            ->where('status', '!=', ReservationStatus::Released->value)
            ->with('part')->orderBy('id')->get();
    }
}
