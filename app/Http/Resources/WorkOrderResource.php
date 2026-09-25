<?php

namespace App\Http\Resources;

use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkOrder */
class WorkOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'device_id' => $this->device_id,
            // Snapshot da entrada, não o cadastro atual.
            'device' => [
                'category' => $this->device_category,
                'brand' => $this->device_brand,
                'model' => $this->device_model,
                'serial_number' => $this->device_serial_number,
            ],
            'reported_problem' => $this->reported_problem,
            'intake_condition' => $this->intake_condition,
            'accessories' => $this->accessories,
            'received_at' => $this->received_at,
        ];
    }
}
