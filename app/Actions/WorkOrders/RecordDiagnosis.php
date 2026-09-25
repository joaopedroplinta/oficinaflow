<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class RecordDiagnosis
{
    /** @param array{diagnosis: string, assigned_technician_id?: int|null} $data */
    public function handle(WorkOrder $order, array $data, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = WorkOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== WorkOrderStatus::Diagnosing) {
                throw new InvalidTransition($order->status, 'registrar o diagnóstico');
            }

            $order->diagnosis = $data['diagnosis'];
            // Chave ausente mantém o responsável; `null` explícito remove.
            if (array_key_exists('assigned_technician_id', $data)) {
                $order->assigned_technician_id = $data['assigned_technician_id'];
            }
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::DiagnosisRecorded,
                'from_status' => WorkOrderStatus::Diagnosing,
                'to_status' => WorkOrderStatus::Diagnosing,
                'metadata' => ['assigned_technician_id' => $order->assigned_technician_id],
            ]);

            return $order->load(['customer', 'technician']);
        });
    }
}
