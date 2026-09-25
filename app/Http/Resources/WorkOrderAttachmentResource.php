<?php

namespace App\Http\Resources;

use App\Models\WorkOrderAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkOrderAttachment */
class WorkOrderAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'is_image' => $this->isImage(),
            'size_bytes' => $this->size_bytes,
            'uploader' => ['id' => $this->uploader->id, 'name' => $this->uploader->name],
            'url' => "/api/v1/work-orders/{$this->work_order_id}/attachments/{$this->id}",
            'created_at' => $this->created_at,
        ];
    }
}
