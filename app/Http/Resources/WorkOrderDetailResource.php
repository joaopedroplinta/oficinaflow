<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WorkOrderDetailResource extends WorkOrderResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'current_quote' => $this->currentQuote ? new QuoteResource($this->currentQuote->loadMissing('items')) : null,
            // Preenchidos pelo card de estoque; o contrato já existe.
            'reservations' => [],
            'pending' => [],
        ];
    }
}
