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
    /**
     * O equipamento sai da assistência: reparado (`ready_for_pickup → delivered`) ou devolvido sem reparo
     * (`awaiting_return → canceled`). Cancelar e entregar são coisas diferentes: só esta Action encerra a ordem.
     */
    public function handle(WorkOrder $order, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            [$to, $type] = match ($order->status) {
                WorkOrderStatus::ReadyForPickup => [WorkOrderStatus::Delivered, WorkOrderEventType::Delivered],
                WorkOrderStatus::AwaitingReturn => [WorkOrderStatus::Canceled, WorkOrderEventType::ReturnedWithoutRepair],
                default => throw InvalidTransition::from($order->status, 'entregar o equipamento'),
            };

            $from = $order->status;
            if ($to === WorkOrderStatus::Delivered) {
                $order->delivered_at = now();
                $order->warranty_until = $order->delivered_at->copy()->addDays(config('oficinaflow.warranty_days'));
            }
            $order->status = $to;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => $type,
                'from_status' => $from,
                'to_status' => $to,
            ]);

            return $order;
        });
    }
}
