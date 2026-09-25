<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Status, snapshot e vínculos só a Action define; o corpo da requisição nunca chega aqui.
#[Fillable(['reported_problem', 'intake_condition', 'accessories'])]
class WorkOrder extends Model
{
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

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
