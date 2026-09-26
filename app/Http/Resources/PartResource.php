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
            // Custo e margem só para o atendimento (PLANO §13.3).
            'average_cost_cents' => $this->when($request->user()?->isAdmin(), $this->average_cost_cents),
            'margin_cents' => $this->when($request->user()?->isAdmin(), $this->average_cost_cents === null ? null : $this->sale_price_cents - $this->average_cost_cents),
            'margin_percent' => $this->when($request->user()?->isAdmin(), $this->average_cost_cents === null || $this->sale_price_cents <= 0 ? null : round(($this->sale_price_cents - $this->average_cost_cents) / $this->sale_price_cents * 100, 1)),
        ];
    }
}
