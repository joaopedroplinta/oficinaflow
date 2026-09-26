<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => $this->status->label(),
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'name' => $this->supplier->name]),
            'notes' => $this->notes,
            // Na listagem vem da subconsulta; no detalhe, dos itens.
            'total_cents' => isset($this->total_cents) ? (int) $this->total_cents : $this->whenLoaded('items', fn () => $this->items->sum(fn ($i) => $i->quantity * $i->unit_cost_cents)),
            'created_by' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'created_at' => $this->created_at,
            'canceled_at' => $this->canceled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'part' => ['id' => $i->part->id, 'sku' => $i->part->sku, 'name' => $i->part->name],
                'quantity' => $i->quantity,
                'unit_cost_cents' => $i->unit_cost_cents,
                'line_total_cents' => $i->quantity * $i->unit_cost_cents,
                'received_quantity' => (int) $i->received_quantity,
                'remaining_quantity' => $i->quantity - (int) $i->received_quantity,
            ])->values()),
        ];
    }
}
