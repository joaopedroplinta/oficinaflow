<?php

namespace App\Models;

use App\Enums\QuoteItemKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

// Preenchido só pela Action, que copia o snapshot do catálogo; nunca direto do corpo da requisição.
#[Fillable(['kind', 'part_id', 'description', 'quantity', 'unit_price_cents', 'position'])]
class QuoteItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['kind' => QuoteItemKind::class];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(PartReservation::class);
    }
}
