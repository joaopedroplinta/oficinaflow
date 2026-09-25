<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveQuoteDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Quote::class);
    }

    // Peça: o servidor copia nome e preço do catálogo. Serviço: descrição e preço vêm do atendente.
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['present', 'array', 'max:50'],
            'items.*.kind' => ['required', 'in:part,service'],
            'items.*.part_id' => ['nullable', 'required_if:items.*.kind,part', 'prohibited_if:items.*.kind,service', 'integer', 'distinct',
                Rule::exists('parts', 'id')->where('active', true)],
            'items.*.description' => ['nullable', 'required_if:items.*.kind,service', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price_cents' => ['nullable', 'required_if:items.*.kind,service', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.part_id.distinct' => 'A mesma peça não pode aparecer duas vezes no orçamento.',
            'items.*.part_id.prohibited_if' => 'Item de serviço não usa peça do catálogo.',
            'items.*.part_id.exists' => 'Peça não encontrada ou inativa.',
        ];
    }
}
