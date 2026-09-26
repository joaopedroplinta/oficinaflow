<?php

namespace App\Http\Requests;

use App\Models\Part;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceivePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('receive', Part::class);
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:200'],
            // Custo unitário da entrada (centavos) e fornecedor: opcionais; sem custo, só o saldo muda.
            'unit_cost_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('active', true)],
        ];
    }

    public function messages(): array
    {
        return ['supplier_id.exists' => 'Fornecedor não encontrado ou inativo.'];
    }
}
