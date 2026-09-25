<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['work_order_id', 'uploaded_by', 'path', 'original_name', 'mime_type', 'size_bytes'])]
class WorkOrderAttachment extends Model
{
    const UPDATED_AT = null;

    public const MAX_PER_ORDER = 20;

    public const MAX_KILOBYTES = 10240;

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
