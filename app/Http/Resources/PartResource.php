<?php

namespace App\Http\Resources;

use App\Models\Part;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Part */
class PartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'sale_price_cents' => $this->sale_price_cents,
            'on_hand' => $this->on_hand,
            'reserved_quantity' => $this->reserved_quantity,
            'available' => $this->on_hand - $this->reserved_quantity,
            'active' => $this->active,
        ];
    }
}
