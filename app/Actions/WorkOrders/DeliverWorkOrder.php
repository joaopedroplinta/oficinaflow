<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class DeliverWorkOrder
{
    /** `ready_for_pickup → delivered`. (A devolução sem reparo, `awaiting_return → canceled`, é do #23.) */
    public function handle(WorkOrder $order, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WorkOrderStatus::ReadyForPickup) {
                throw InvalidTransition::from($order->status, 'entregar o equipamento');
            }

            $order->delivered_at = now();
            $order->status = WorkOrderStatus::Delivered;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::Delivered,
                'from_status' => WorkOrderStatus::ReadyForPickup,
                'to_status' => WorkOrderStatus::Delivered,
            ]);

            return $order;
        });
    }
}
