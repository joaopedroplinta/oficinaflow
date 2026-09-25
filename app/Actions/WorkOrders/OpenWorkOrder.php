<?php

namespace App\Actions\WorkOrders;

use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use Illuminate\Support\Facades\DB;

class OpenWorkOrder
{
    /** @param array{device_id: int, reported_problem: string, intake_condition: string, accessories: string} $data */
    public function handle(array $data, User $actor): WorkOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            // Trava o equipamento: o snapshot é o estado que será commitado, sem edição concorrente no meio.
            $device = Device::whereKey($data['device_id'])->lockForUpdate()->firstOrFail();

            $order = new WorkOrder($data);
            $order->customer_id = $device->customer_id;
            $order->device_id = $device->id;
            $order->status = WorkOrderStatus::Received;
            $order->device_category = $device->category;
            $order->device_brand = $device->brand;
            $order->device_model = $device->model;
            $order->device_serial_number = $device->serial_number;
            $order->received_at = now();
            $order->save();

            WorkOrderEvent::create([
                'work_order_id' => $order->id,
                'actor_id' => $actor->id,
                'type' => WorkOrderEventType::Opened,
                'to_status' => WorkOrderStatus::Received,
            ]);

            // Relê para trazer o `number` gerado pelo banco.
            return $order->refresh()->load('customer');
        });
    }
}
