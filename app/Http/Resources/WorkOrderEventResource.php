<?php

namespace App\Http\Resources;

use App\Models\WorkOrderEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkOrderEvent */
class WorkOrderEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'from_status' => $this->from_status,
            'from_status_label' => $this->from_status?->label(),
            'to_status' => $this->to_status,
            'to_status_label' => $this->to_status?->label(),
            // Só o que a tela usa: o metadata guarda também nome do cliente, motivo, destinatário etc.
            'metadata' => (object) array_intersect_key($this->metadata ?? [], ['channel_label' => true]),
            'actor' => ['id' => $this->actor->id, 'name' => $this->actor->name],
            'created_at' => $this->created_at,
        ];
    }
}
