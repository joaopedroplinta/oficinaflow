<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementKind;
use App\Enums\QuoteDecisionType;
use App\Enums\QuoteItemKind;
use App\Enums\ReservationStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class StartRepair
{
    /**
     * Inicia o reparo consumindo as peças reservadas: `on_hand` e `reserved_quantity` baixam juntos, as reservas
     * viram `consumed`, cada consumo entra no livro-razão e a ordem vai a `in_progress`. Tudo ou nada: qualquer
     * falha desfaz estado, saldo, reservas e movimentos. Repetir depois de sucesso não consome de novo.
     */
    public function handle(WorkOrder $order, int $seenQuoteId, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $seenQuoteId, $actor) {
            // Ordem primeiro; tudo o que vem depois lê estado já travado.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->current_quote_id === null) {
                throw new InvalidTransition('Esta ordem ainda não tem orçamento.');
            }
            if ($order->current_quote_id !== $seenQuoteId) {
                throw new QuoteSuperseded;
            }
            // Idempotência: o reparo já começou sobre esta versão — nada a fazer, nada é consumido de novo.
            if ($order->status === WorkOrderStatus::InProgress) {
                return $order;
            }
            if ($order->status !== WorkOrderStatus::Approved) {
                throw InvalidTransition::from($order->status, 'iniciar o reparo');
            }

            $quote = Quote::whereKey($order->current_quote_id)->with(['decision', 'items.reservations'])->firstOrFail();
            if ($quote->decision?->decision !== QuoteDecisionType::Approved) {
                throw new InvalidTransition('O orçamento atual não tem aprovação registrada.');
            }

            $partItems = $quote->items->where('kind', QuoteItemKind::Part);
            $unreserved = $partItems->filter(fn ($i) => $i->missingQuantity() > 0);
            if ($unreserved->isNotEmpty()) {
                $missing = $unreserved->map(fn ($i) => ['part_id' => $i->part_id, 'name' => $i->description, 'quantity' => $i->missingQuantity()])->values()->all();

                throw new InvalidTransition('Faltam reservas para: '.collect($missing)->pluck('name')->join(', ').'. Reserve as peças antes de iniciar o reparo.', ['missing' => $missing]);
            }

            // Peças em ordem crescente de ID (protocolo de locks).
            $reservations = $partItems->flatMap->reservations->where('status', ReservationStatus::Active)->sortBy(['part_id', 'id'])->values();
            $parts = Part::whereIn('id', $reservations->pluck('part_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($reservations as $reservation) {
                $part = $parts[$reservation->part_id];
                $part->on_hand -= $reservation->quantity;
                $part->reserved_quantity -= $reservation->quantity;
                $part->save();

                $reservation->status = ReservationStatus::Consumed;
                $reservation->consumed_at = now();
                $reservation->save();

                $movement = new InventoryMovement;
                $movement->part_id = $part->id;
                $movement->kind = InventoryMovementKind::Consumption;
                $movement->quantity = $reservation->quantity;
                $movement->reservation_id = $reservation->id;
                $movement->reason = "Consumo na ordem #{$order->number}";
                $movement->created_by = $actor->id;
                $movement->save();
            }

            $order->status = WorkOrderStatus::InProgress;
            $order->started_at = now();
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::RepairStarted,
                'from_status' => WorkOrderStatus::Approved,
                'to_status' => WorkOrderStatus::InProgress,
                'metadata' => [
                    'quote_id' => $quote->id,
                    'consumed' => $reservations->map(fn ($r) => ['part_id' => $r->part_id, 'quantity' => $r->quantity])->all(),
                ],
            ]);

            return $order;
        });
    }
}
