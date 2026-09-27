<?php

namespace App\Http\Resources;

use App\Enums\QuoteItemKind;
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
            // Custo médio ATUAL da peça (não é congelado) e margem da linha: só para o atendimento, nunca no PDF.
            'unit_cost_cents' => $this->when($this->showsCost($request), fn () => $this->part->average_cost_cents),
            'line_margin_cents' => $this->when($this->showsCost($request), fn () => $this->part->average_cost_cents === null ? null : $this->quantity * ($this->unit_price_cents - $this->part->average_cost_cents)),
        ];
    }

    private function showsCost(Request $request): bool
    {
        return $request->user()?->isAdmin() && $this->kind === QuoteItemKind::Part && $this->relationLoaded('part') && $this->part !== null;
    }
}
