<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['part_id', 'quantity', 'unit_cost_cents'])]
class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Quanto já entrou no estoque contra este item (soma dos movimentos de entrada vinculados). */
    public function receivedQuantity(): int
    {
        return (int) $this->receipts()->sum('quantity');
    }
}
