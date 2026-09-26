<?php

namespace App\Http\Requests;

use App\Models\PurchaseOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PurchaseOrder::class);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.part_id' => ['required', 'integer', 'distinct', Rule::exists('parts', 'id')->where('active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'Fornecedor não encontrado ou inativo.',
            'items.required' => 'Adicione ao menos uma peça ao pedido.',
            'items.min' => 'Adicione ao menos uma peça ao pedido.',
            'items.*.part_id.exists' => 'Peça não encontrada ou inativa.',
            'items.*.part_id.distinct' => 'A mesma peça aparece duas vezes no pedido.',
        ];
    }
}
