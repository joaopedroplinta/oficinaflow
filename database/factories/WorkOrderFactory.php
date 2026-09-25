<?php

namespace Database\Factories;

use App\Enums\WorkOrderStatus;
use App\Models\Device;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkOrder> */
class WorkOrderFactory extends Factory
{
    public function definition(): array
    {
        $device = Device::factory()->create();

        return [
            'customer_id' => $device->customer_id,
            'device_id' => $device->id,
            'status' => WorkOrderStatus::Received,
            'device_category' => $device->category,
            'device_brand' => $device->brand,
            'device_model' => $device->model,
            'device_serial_number' => $device->serial_number,
            'reported_problem' => fake()->sentence(),
            'intake_condition' => fake()->sentence(),
            'accessories' => 'Carregador',
            'received_at' => now(),
        ];
    }

    // Estados de execução vêm com as datas e a descrição que o banco exige (work_orders_execution_check).
    public function status(WorkOrderStatus $status): static
    {
        $reached = fn (WorkOrderStatus ...$set) => in_array($status, $set, true);
        $started = $reached(WorkOrderStatus::InProgress, WorkOrderStatus::ReadyForPickup, WorkOrderStatus::Delivered);
        $ready = $reached(WorkOrderStatus::ReadyForPickup, WorkOrderStatus::Delivered);

        return $this->state(array_filter([
            'status' => $status,
            'started_at' => $started ? now()->subHours(3) : null,
            'ready_at' => $ready ? now()->subHour() : null,
            'work_performed' => $ready ? 'Serviço executado.' : null,
            'delivered_at' => $reached(WorkOrderStatus::Delivered) ? now() : null,
        ], fn ($v) => $v !== null));
    }
}
