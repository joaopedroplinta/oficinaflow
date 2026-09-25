<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class StartDiagnosis
{
    public function handle(WorkOrder $order, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            // Estado é lido depois do lock: o que veio da rota pode estar velho.
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WorkOrderStatus::Received) {
                throw InvalidTransition::from($order->status, 'iniciar o diagnóstico');
            }

            $order->status = WorkOrderStatus::Diagnosing;
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::DiagnosisStarted,
                'from_status' => WorkOrderStatus::Received,
                'to_status' => WorkOrderStatus::Diagnosing,
            ]);

            return $order->load(['customer', 'technician']);
        });
    }
}
