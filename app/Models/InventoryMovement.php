<?php

namespace App\Models;

use App\Enums\InventoryMovementKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Só as Actions de estoque gravam, campo a campo; a tabela recusa UPDATE e DELETE.
#[Fillable([])]
class InventoryMovement extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['kind' => InventoryMovementKind::class];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
