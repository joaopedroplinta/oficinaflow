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
            'kind' => $this->kind,
            'kind_label' => $this->kind->label(),
            // Numeração própria: versão 1, 2… ou adendo 1, 2…
            'version' => $this->version,
            'base_quote_id' => $this->base_quote_id,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'total_cents' => $this->total_cents,
            'issued_at' => $this->issued_at,
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
            'decision' => new QuoteDecisionResource($this->whenLoaded('decision')),
        ];
    }
}
