<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderKind;
use App\Enums\WorkOrderStatus;
use App\Exceptions\InvalidTransition;
use App\Exceptions\ReturnAlreadyOpen;
use App\Exceptions\WarrantyExpired;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class OpenReturn
{
    /**
     * Garantia ou retrabalho de uma ordem entregue: abre uma NOVA ordem vinculada; a original (final) fica intacta.
     *
     * @param  array{reported_problem: string, intake_condition: string, accessories: string}  $data
     */
    public function handle(WorkOrder $origin, WorkOrderKind $kind, array $data, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($origin, $kind, $data, $actor) {
            // A original é travada primeiro: dois retornos simultâneos se serializam aqui (o índice único é a rede de segurança).
            $origin = WorkOrder::whereKey($origin->id)->lockForUpdate()->firstOrFail();

            if ($origin->status !== WorkOrderStatus::Delivered) {
                throw InvalidTransition::from($origin->status, 'abrir garantia ou retrabalho');
            }
            if ($kind === WorkOrderKind::Warranty && $origin->warranty_until->isPast()) {
                throw new WarrantyExpired($origin->warranty_until);
            }
            $open = WorkOrder::where('origin_order_id', $origin->id)
                ->whereNotIn('status', [WorkOrderStatus::Delivered->value, WorkOrderStatus::Canceled->value])
                ->first();
            if ($open) {
                throw new ReturnAlreadyOpen($open->number);
            }

            $order = new WorkOrder($data);
            $order->customer_id = $origin->customer_id;
            $order->device_id = $origin->device_id;
            $order->kind = $kind;
            $order->origin_order_id = $origin->id;
            $order->status = WorkOrderStatus::Received;
            // Mesmo equipamento: o snapshot é o da entrada original.
            $order->device_category = $origin->device_category;
            $order->device_brand = $origin->device_brand;
            $order->device_model = $origin->device_model;
            $order->device_serial_number = $origin->device_serial_number;
            $order->received_at = now();
            $order->save();
            $order->refresh();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::Opened,
                'to_status' => WorkOrderStatus::Received,
                'metadata' => ['kind' => $kind->value, 'origin_order_id' => $origin->id],
            ]);
            WorkOrderEvent::create([
                'work_order_id' => $origin->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::ReturnOpened,
                'metadata' => ['kind' => $kind->value, 'return_order_id' => $order->id, 'return_number' => $order->number],
            ]);

            return $order->load('customer', 'origin');
        });
    }
}
