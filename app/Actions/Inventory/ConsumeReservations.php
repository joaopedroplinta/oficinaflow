<?php

namespace App\Actions\Inventory;

use App\Enums\InventoryMovementKind;
use App\Enums\ReservationStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\QuoteSuperseded;
use App\Models\InventoryMovement;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsumeReservations
{
    /**
     * Com o reparo em andamento: baixa as reservas ativas (as feitas depois do início, ao reservar o que faltava).
     * Sem reserva ativa não há o que consumir: devolve vazio e não grava nada (repetir é inofensivo).
     *
     * @return Collection<int, PartReservation> as reservas consumidas agora
     */
    public function handle(WorkOrder $order, int $seenQuoteId, User $actor): Collection
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
            if ($order->status !== WorkOrderStatus::InProgress) {
                throw InvalidTransition::from($order->status, 'consumir as peças reservadas');
            }

            $consumed = $this->consumeActive($order, $actor);

            if ($consumed->isNotEmpty()) {
                WorkOrderEvent::create([
                    'work_order_id' => $order->id,
                    'actor_id' => $actor->id,
                    'type' => WorkOrderEventType::PartsConsumed,
                    'from_status' => $order->status,
                    'to_status' => $order->status,
                    'metadata' => ['quote_id' => $order->current_quote_id, 'consumed' => $this->summary($consumed)],
                ]);
            }

            return $consumed->each->load('part');
        });
    }

    /**
     * Baixa todas as reservas ativas da versão atual: `on_hand` e `reserved_quantity` caem juntos, a reserva vira
     * `consumed` e o consumo entra no livro-razão. Quem chama já travou a ordem (peças aqui, em ID crescente).
     *
     * @return Collection<int, PartReservation>
     */
    public function consumeActive(WorkOrder $order, User $actor): Collection
    {
        $active = PartReservation::where('status', ReservationStatus::Active->value)
            // Da ordem inteira: versão atual e adendos aprovados (só eles têm reserva ativa).
            ->whereHas('quoteItem.quote', fn ($q) => $q->where('work_order_id', $order->id))
            ->orderBy('part_id')->orderBy('id')->lockForUpdate()->get();
        if ($active->isEmpty()) {
            return $active;
        }

        // Peças em ordem crescente de ID (protocolo de locks).
        $parts = Part::whereIn('id', $active->pluck('part_id')->unique()->sort()->values())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($active as $reservation) {
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

        return $active;
    }

    /** @param Collection<int, PartReservation> $reservations */
    public function summary(Collection $reservations): array
    {
        return $reservations->map(fn ($r) => ['part_id' => $r->part_id, 'quantity' => $r->quantity])->values()->all();
    }
}
