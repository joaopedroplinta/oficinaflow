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

    public function status(WorkOrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
