<?php

namespace App\Actions\Inventory;

use App\Enums\ReservationStatus;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReleaseReservations
{
    /**
     * Libera as reservas ativas da ordem (revisão de orçamento, cancelamento). Só mexe em `reserved_quantity`:
     * o saldo físico (`on_hand`) e o livro-razão não mudam, porque nada saiu da prateleira.
     * Quem chama grava o evento, incluindo o que foi liberado.
     *
     * @return Collection<int, PartReservation> as reservas que foram liberadas
     */
    public function handle(WorkOrder $order): Collection
    {
        return DB::transaction(function () use ($order) {
            // Ordem primeiro (quem chama normalmente já a travou; repetir é inofensivo); depois as peças por ID crescente.
            WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $active = PartReservation::where('status', ReservationStatus::Active->value)
                ->whereHas('quoteItem.quote', fn ($q) => $q->where('work_order_id', $order->id))
                ->orderBy('id')->lockForUpdate()->get();
            if ($active->isEmpty()) {
                return $active;
            }

            $parts = Part::whereIn('id', $active->pluck('part_id')->unique()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($active as $reservation) {
                $part = $parts[$reservation->part_id];
                $part->reserved_quantity -= $reservation->quantity;
                $part->save();

                $reservation->status = ReservationStatus::Released;
                $reservation->released_at = now();
                $reservation->save();
            }

            return $active;
        });
    }
}
