<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovement */
class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => $this->kind->label(),
            'quantity' => $this->quantity,
            'reason' => $this->reason,
            'author' => ['id' => $this->author->id, 'name' => $this->author->name],
            'created_at' => $this->created_at,
        ];
    }
}
