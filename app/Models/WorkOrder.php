<?php

namespace App\Models;

use App\Enums\QuoteDecisionType;
use App\Enums\QuoteItemKind;
use App\Enums\QuoteKind;
use App\Enums\WorkOrderKind;
use App\Enums\WorkOrderStatus;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Status, snapshot e vínculos só a Action define; o corpo da requisição nunca chega aqui.
#[Fillable(['reported_problem', 'intake_condition', 'accessories'])]
class WorkOrder extends Model
{
    /** @use HasFactory<WorkOrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'kind' => WorkOrderKind::class,
            'warranty_until' => 'datetime',
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'ready_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_id');
    }

    public function currentQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'current_quote_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** Ordem entregue que originou esta (garantia ou retrabalho). */
    public function origin(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origin_order_id');
    }

    /** Ordens de garantia/retrabalho abertas a partir desta. */
    public function returns(): HasMany
    {
        return $this->hasMany(self::class, 'origin_order_id');
    }

    /** Adendos ao orçamento (criados com o reparo em andamento). */
    public function addenda(): HasMany
    {
        return $this->hasMany(Quote::class)->where('kind', QuoteKind::Addendum->value);
    }

    /**
     * Itens de peça que valem para reserva e consumo: os da versão atual e os de adendos APROVADOS (adendo
     * rascunho, emitido sem decisão ou rejeitado não reserva nada). Com as peças e reservas carregadas.
     *
     * @return Collection<int, QuoteItem>
     */
    public function reservableItems(): Collection
    {
        return QuoteItem::where('kind', QuoteItemKind::Part->value)
            ->whereHas('quote', fn ($q) => $q->where('work_order_id', $this->id)->where(
                fn ($w) => $w->where('id', $this->current_quote_id)->orWhere(
                    fn ($a) => $a->where('kind', QuoteKind::Addendum->value)->whereHas('decision', fn ($d) => $d->where('decision', QuoteDecisionType::Approved->value)),
                ),
            ))
            ->with(['part', 'reservations.part'])->orderBy('quote_id')->orderBy('position')->get();
    }

    /** Total da ordem: a versão atual mais os adendos aprovados. */
    public function totalCents(): int
    {
        $base = (int) $this->currentQuote()->value('total_cents');
        $addenda = (int) $this->addenda()->whereHas('decision', fn ($d) => $d->where('decision', QuoteDecisionType::Approved->value))->sum('total_cents');

        return $base + $addenda;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkOrderEvent::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * "Aguardando peças": ordem aprovada cuja versão atual tem item de peça ainda sem reserva. É um indicador
     * calculado (não um estado da ordem) e cabe numa subconsulta, então a listagem não faz N+1.
     */
    private const AWAITING_PARTS_SQL = "work_orders.status IN ('approved', 'in_progress') AND EXISTS (
        SELECT 1 FROM quote_items qi JOIN quotes q ON q.id = qi.quote_id
        WHERE qi.kind = 'part' AND q.work_order_id = work_orders.id
        AND (q.id = work_orders.current_quote_id
             OR (q.kind = 'addendum' AND EXISTS (SELECT 1 FROM quote_decisions d WHERE d.quote_id = q.id AND d.decision = 'approved')))
        AND qi.quantity > COALESCE((SELECT SUM(pr.quantity) FROM part_reservations pr WHERE pr.quote_item_id = qi.id AND pr.status <> 'released'), 0)
    )";

    public function scopeWithAwaitingParts(Builder $query): Builder
    {
        return $query->select('work_orders.*')->selectRaw('('.self::AWAITING_PARTS_SQL.') as awaiting_parts');
    }

    public function scopeAwaitingParts(Builder $query): Builder
    {
        return $query->whereRaw(self::AWAITING_PARTS_SQL);
    }
}
