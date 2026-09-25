<?php

namespace App\Models;

use App\Enums\DecisionChannel;
use App\Enums\QuoteDecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Só a Action grava (sem Fillable); a tabela recusa UPDATE e DELETE.
class QuoteDecision extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'decision' => QuoteDecisionType::class,
            'channel' => DecisionChannel::class,
            'decided_at' => 'datetime',
        ];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
