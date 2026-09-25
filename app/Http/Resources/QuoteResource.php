<?php

namespace App\Http\Resources;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Quote */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_id' => $this->work_order_id,
            'version' => $this->version,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'total_cents' => $this->total_cents,
            'issued_at' => $this->issued_at,
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
