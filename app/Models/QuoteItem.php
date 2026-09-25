<?php

namespace App\Models;

use App\Enums\QuoteItemKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Preenchido só pela Action, que copia o snapshot do catálogo; nunca direto do corpo da requisição.
#[Fillable(['kind', 'part_id', 'description', 'quantity', 'unit_price_cents', 'position'])]
class QuoteItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['kind' => QuoteItemKind::class];
    }
}
