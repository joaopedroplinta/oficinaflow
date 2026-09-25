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
            // O total consumido que a tela mostrava: comparado com o do livro-razão depois do lock.
            'parts.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['parts.*.action.in' => 'Escolha se a peça volta ao estoque ou fica baixada.', 'parts.*.action.required' => 'Escolha se a peça volta ao estoque ou fica baixada.', 'parts.*.quantity.required' => 'Informe a quantidade consumida que você viu.'];
    }
}
