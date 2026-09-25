<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;

class CancelWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', $this->route('workOrder') ?? WorkOrder::class);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // Em reparo: uma decisão por peça já consumida (a Action confere que cobrem exatamente as consumidas).
            'parts' => ['sometimes', 'array'],
            'parts.*.part_id' => ['required', 'integer', 'distinct'],
            'parts.*.action' => ['required', 'in:return,keep'],
        ];
    }

    public function messages(): array
    {
        return ['parts.*.action.in' => 'Escolha se a peça volta ao estoque ou fica baixada.', 'parts.*.action.required' => 'Escolha se a peça volta ao estoque ou fica baixada.'];
    }
}
