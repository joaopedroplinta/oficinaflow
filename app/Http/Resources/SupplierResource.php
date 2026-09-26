<?php

namespace App\Http\Resources;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact' => $this->contact,
            'notes' => $this->notes,
            'active' => $this->active,
            'created_at' => $this->created_at,
        ];
    }
}
