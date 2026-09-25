<?php

namespace App\Models;

use App\Enums\QuoteItemKind;
use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** Um item pode ter várias reservas (reserva parcial); a liberada fica no histórico. */
    public function reservations(): HasMany
    {
        return $this->hasMany(PartReservation::class);
    }

    /** Quanto deste item está reservado (ativas) ou já foi consumido. Usa `reservations` carregadas. */
    public function reservedQuantity(): int
    {
        return $this->reservations->where('status', '!=', ReservationStatus::Released)->sum('quantity');
    }

    public function missingQuantity(): int
    {
        return max(0, $this->quantity - $this->reservedQuantity());
    }

    /** Situação da reserva para exibição (ficha em PDF). */
    public function reservationLabel(): string
    {
        if ($this->kind !== QuoteItemKind::Part) {
            return '—';
        }
        $held = $this->reservedQuantity();
        if ($held === 0) {
            return 'Sem reserva';
        }
        if ($held < $this->quantity) {
            return "Reservada {$held} de {$this->quantity}";
        }

        return $this->reservations->where('status', ReservationStatus::Active)->isEmpty() ? ReservationStatus::Consumed->label() : ReservationStatus::Active->label();
    }
}
