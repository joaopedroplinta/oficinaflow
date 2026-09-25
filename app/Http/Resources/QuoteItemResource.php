<?php

namespace App\Http\Resources;

use App\Models\QuoteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuoteItem */
class QuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => $this->kind->label(),
            'part_id' => $this->part_id,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price_cents' => $this->unit_price_cents,
            'line_total_cents' => $this->quantity * $this->unit_price_cents,
            'position' => $this->position,
        ];
    }
}
