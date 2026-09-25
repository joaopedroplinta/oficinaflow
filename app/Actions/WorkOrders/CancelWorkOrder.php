<?php

namespace App\Actions\WorkOrders;

use App\Actions\Inventory\ReleaseReservations;
use App\Actions\Inventory\ReturnConsumedParts;
use App\Enums\ReservationStatus;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\Part;
use App\Models\PartReservation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class CancelWorkOrder
{
    /** Estados em que ainda dá para cancelar. Em `in_progress` há peça já consumida: é preciso dizer o que fazer com ela. */
    private const CANCELABLE = [WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved, WorkOrderStatus::InProgress];

    /**
     * Cancela o serviço: as reservas ativas são liberadas (sem baixar o saldo físico) e a ordem vai a `awaiting_return`.
     * Cancelar não é devolver nem entregar: o equipamento continua na assistência até `deliver`.
     * Em reparo, cada peça já consumida volta ao estoque (estorno no livro-razão) ou fica baixada, conforme `$partDecisions`
     * (part_id => 'return'|'keep'); o consumo nunca é apagado.
     *
     * @param  array<int, string>  $partDecisions
     */
    public function handle(WorkOrder $order, string $reason, User $actor, array $partDecisions = []): WorkOrder
    {
        return DB::transaction(function () use ($order, $reason, $actor, $partDecisions) {
            // Estado lido depois do lock: o que veio da rota pode estar velho.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, self::CANCELABLE, true)) {
                throw InvalidTransition::from($order->status, 'cancelar o serviço');
            }

            // Todas as peças que este cancelamento toca (reservas a liberar + consumos a estornar) são travadas de
            // uma vez, em ID crescente: liberar e estornar em duas passadas poderia cruzar com outra ordem (deadlock).
            $returns = app(ReturnConsumedParts::class);
            $touched = PartReservation::where('status', ReservationStatus::Active->value)
                ->whereHas('quoteItem.quote', fn ($q) => $q->where('work_order_id', $order->id))->pluck('part_id')
                ->merge($returns->consumptionsOf($order)->pluck('part_id'))->unique()->sort()->values();
            Part::whereIn('id', $touched)->orderBy('id')->lockForUpdate()->get();

            $released = app(ReleaseReservations::class)->handle($order);
            $stock = $returns->handle($order, $partDecisions, $actor);

            $from = $order->status;
            $order->cancellation_reason = trim($reason);
            $order->status = WorkOrderStatus::AwaitingReturn;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::Canceled,
                'from_status' => $from,
                'to_status' => WorkOrderStatus::AwaitingReturn,
                'metadata' => [
                    'reason' => $order->cancellation_reason,
                    'released_reservations' => $released->map(fn ($r) => ['part_id' => $r->part_id, 'quantity' => $r->quantity])->all(),
                    'returned_parts' => $stock['returned'],
                    'kept_parts' => $stock['kept'],
                ],
            ]);

            return $order;
        });
    }
}
