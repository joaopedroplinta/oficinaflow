<?php

namespace App\Models;

use App\Enums\QuoteKind;
use App\Enums\QuoteStatus;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// Versão, status e total são definidos só pelas Actions.
#[Fillable(['notes'])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    // Espelha o default do banco, para o model recém-criado já saber o tipo.
    protected $attributes = ['kind' => 'version'];

    protected function casts(): array
    {
        return ['status' => QuoteStatus::class, 'kind' => QuoteKind::class, 'issued_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** Adendo: a versão aprovada sobre a qual ele foi criado. */
    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_quote_id');
    }

    public function isAddendum(): bool
    {
        return $this->kind === QuoteKind::Addendum;
    }

    public function decision(): HasOne
    {
        return $this->hasOne(QuoteDecision::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }
}
