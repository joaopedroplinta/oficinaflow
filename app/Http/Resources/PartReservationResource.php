<?php

namespace App\Http\Resources;

use App\Models\PartReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PartReservation */
class PartReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quote_item_id' => $this->quote_item_id,
            'part' => ['id' => $this->part->id, 'sku' => $this->part->sku, 'name' => $this->part->name],
            'quantity' => $this->quantity,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'reserved_at' => $this->reserved_at,
        ];
    }
}
