<?php

namespace App\Actions\Inventory;

use App\Enums\QuoteDecisionType;
use App\Enums\QuoteItemKind;
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
     * Reserva todas as peças da versão aprovada ou nenhuma. Repetir depois de sucesso devolve as reservas
     * existentes. Não muda o estado da ordem (aprovação e disponibilidade são independentes).
     *
     * @return Collection<int, PartReservation>
     */
    public function handle(WorkOrder $order, int $seenQuoteId, User $actor): Collection
    {
        return DB::transaction(function () use ($order, $seenQuoteId, $actor) {
            // 1. Ordem primeiro; tudo o que vem depois lê estado já travado.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->current_quote_id === null) {
                throw new InvalidTransition('Esta ordem ainda não tem orçamento.');
            }
            if ($order->current_quote_id !== $seenQuoteId) {
                throw new QuoteSuperseded;
            }
            if ($order->status !== WorkOrderStatus::Approved) {
                throw InvalidTransition::from($order->status, 'reservar as peças');
            }

            $quote = Quote::whereKey($order->current_quote_id)->with(['decision', 'items.reservation'])->firstOrFail();
            if ($quote->decision?->decision !== QuoteDecisionType::Approved) {
                throw new InvalidTransition('O orçamento atual não tem aprovação registrada.');
            }

            $partItems = $quote->items->where('kind', QuoteItemKind::Part);
            $missing = $partItems->filter(fn ($item) => $item->reservation === null);

            // Idempotência: já reservado (por completo) devolve o que existe, sem tocar em nada.
            if ($missing->isEmpty()) {
                return $partItems->map(fn ($item) => $item->reservation->load('part'))->values();
            }

            // 2. Peças em ordem crescente de ID (protocolo de locks; evita deadlock entre Actions).
            $parts = Part::whereIn('id', $missing->pluck('part_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // 3. Tudo ou nada: confere todas antes de gravar qualquer uma.
            $shortages = $missing
                ->filter(fn ($item) => $parts[$item->part_id]->on_hand - $parts[$item->part_id]->reserved_quantity < $item->quantity)
                ->map(fn ($item) => [
                    'part_id' => $item->part_id,
                    'sku' => $parts[$item->part_id]->sku,
                    'name' => $parts[$item->part_id]->name,
                    'needed' => $item->quantity,
                    'available' => $parts[$item->part_id]->on_hand - $parts[$item->part_id]->reserved_quantity,
                ])->values()->all();
            if ($shortages !== []) {
                throw new InsufficientStock($shortages);
            }

            foreach ($missing as $item) {
                $part = $parts[$item->part_id];
                $part->reserved_quantity += $item->quantity;
                $part->save();

                $reservation = new PartReservation;
                $reservation->quote_item_id = $item->id;
                $reservation->part_id = $part->id;
                $reservation->quantity = $item->quantity;
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
                    'reservations' => $missing->map(fn ($item) => ['part_id' => $item->part_id, 'quantity' => $item->quantity])->values()->all(),
                ],
            ]);

            return $quote->items()->where('kind', QuoteItemKind::Part->value)->with('reservation.part')->get()->map->reservation->values();
        });
    }
}
