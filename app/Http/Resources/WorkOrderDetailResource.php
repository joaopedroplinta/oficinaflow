<?php

namespace App\Http\Resources;

use App\Enums\QuoteItemKind;
use App\Enums\WorkOrderStatus;
use Illuminate\Http\Request;

class WorkOrderDetailResource extends WorkOrderResource
{
    public function toArray(Request $request): array
    {
        $items = $this->currentQuote?->items()->with(['reservations.part', 'part'])->get() ?? collect();
        $reservations = $items->flatMap->reservations->sortBy('id')->values();
        // Peças da versão aprovada que ainda não têm reserva (aguardando peças): indicador, não um estado da ordem.
        $pending = $this->status === WorkOrderStatus::Approved
            ? $items->filter(fn ($i) => $i->kind === QuoteItemKind::Part && $i->missingQuantity() > 0)->map(fn ($i) => [
                'part_id' => $i->part_id,
                'sku' => $i->part->sku,
                'name' => $i->part->name,
                // `needed` = o que ainda falta reservar (na reserva parcial, o item pode estar reservado só em parte).
                'needed' => $i->missingQuantity(),
                'reserved' => $i->reservedQuantity(),
                'quantity' => $i->quantity,
                'available' => $i->part->on_hand - $i->part->reserved_quantity,
            ])->values()->all()
            : [];

        return parent::toArray($request) + [
            'current_quote' => $this->currentQuote ? new QuoteResource($this->currentQuote->loadMissing(['items', 'decision.recorder'])) : null,
            'reservations' => PartReservationResource::collection($reservations),
            'pending' => $pending,
            'returns' => $this->whenLoaded('returns', fn () => $this->returns->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'kind' => $r->kind, 'kind_label' => $r->kind->label(), 'status' => $r->status, 'status_label' => $r->status->label(),
            ])->values()),
        ];
    }
}
