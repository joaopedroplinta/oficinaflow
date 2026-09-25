<?php

namespace App\Models;

use App\Enums\WorkOrderEventType;
use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['work_order_id', 'actor_id', 'type', 'from_status', 'to_status', 'metadata'])]
class WorkOrderEvent extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => WorkOrderEventType::class,
            'from_status' => WorkOrderStatus::class,
            'to_status' => WorkOrderStatus::class,
            'metadata' => 'array',
        ];
    }
}
