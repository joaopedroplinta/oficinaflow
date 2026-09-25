<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WorkOrderDetailResource extends WorkOrderResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            // Preenchidos pelos cards de orçamento e estoque; o contrato já existe.
            'current_quote' => null,
            'reservations' => [],
            'pending' => [],
        ];
    }
}
