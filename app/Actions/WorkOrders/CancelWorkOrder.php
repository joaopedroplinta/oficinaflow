<?php

namespace App\Actions\WorkOrders;

use App\Actions\Inventory\ReleaseReservations;
use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class CancelWorkOrder
{
    /** Estados em que ainda dá para cancelar: o reparo não começou (PLANO §5). */
    private const CANCELABLE = [WorkOrderStatus::Received, WorkOrderStatus::Diagnosing, WorkOrderStatus::AwaitingApproval, WorkOrderStatus::Approved];

    /**
     * Cancela o serviço: as reservas são liberadas (sem baixar o saldo físico) e a ordem vai a `awaiting_return`.
     * Cancelar não é devolver nem entregar: o equipamento continua na assistência até `deliver`.
     */
    public function handle(WorkOrder $order, string $reason, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $reason, $actor) {
            // Estado lido depois do lock: o que veio da rota pode estar velho.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, self::CANCELABLE, true)) {
                throw InvalidTransition::from($order->status, 'cancelar o serviço');
            }

            // Peças travadas em ordem crescente de ID dentro da liberação; só mexe em reserved_quantity.
            $released = app(ReleaseReservations::class)->handle($order);

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
                ],
            ]);

            return $order;
        });
    }
}
