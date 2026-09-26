<?php

namespace App\Http\Resources;

use App\Enums\QuoteItemKind;
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
            // Só para o atendimento: custo das peças (custo médio atual) e margem da versão; serviço não tem custo.
            'cost_cents' => $this->when($this->showsCost($request), fn () => $this->items->where('kind', QuoteItemKind::Part)->sum(fn ($i) => $i->quantity * (int) $i->part->average_cost_cents)),
            'margin_cents' => $this->when($this->showsCost($request), fn () => $this->total_cents - $this->items->where('kind', QuoteItemKind::Part)->sum(fn ($i) => $i->quantity * (int) $i->part->average_cost_cents)),
            // Falso quando alguma peça ainda não tem custo médio: o custo e a margem são parciais.
            'cost_complete' => $this->when($this->showsCost($request), fn () => $this->items->where('kind', QuoteItemKind::Part)->every(fn ($i) => $i->part->average_cost_cents !== null)),
            'decision' => new QuoteDecisionResource($this->whenLoaded('decision')),
        ];
    }

    private function showsCost(Request $request): bool
    {
        return $request->user()?->isAdmin() && $this->relationLoaded('items') && $this->items->where('kind', QuoteItemKind::Part)->every(fn ($i) => $i->relationLoaded('part') && $i->part !== null);
    }
}
