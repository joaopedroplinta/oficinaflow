<?php

namespace App\Http\Resources;

use App\Models\QuoteDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuoteDecision */
class QuoteDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'decision' => $this->decision,
            'decision_label' => $this->decision->label(),
            'customer_name' => $this->customer_name,
            'channel' => $this->channel,
            'channel_label' => $this->channel->label(),
            'decided_at' => $this->decided_at,
            'recorded_by' => $this->whenLoaded('recorder', fn () => ['id' => $this->recorder->id, 'name' => $this->recorder->name]),
            'notes' => $this->notes,
        ];
    }
}
