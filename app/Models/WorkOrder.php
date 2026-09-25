<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    private const AWAITING_PARTS_SQL = "work_orders.status = 'approved' AND EXISTS (
        SELECT 1 FROM quote_items qi
        WHERE qi.quote_id = work_orders.current_quote_id AND qi.kind = 'part'
        AND NOT EXISTS (SELECT 1 FROM part_reservations pr WHERE pr.quote_item_id = qi.id)
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
