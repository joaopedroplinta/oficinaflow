<?php

namespace App\Http\Requests;

use App\Models\Part;
use Illuminate\Foundation\Http\FormRequest;

class StorePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Part::class);
    }

    // SKU em maiúsculas: `tela-15` e `TELA-15` não podem coexistir.
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }

    // Sem saldo aqui: a peça nasce com 0 e o estoque só entra por recebimento.
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:40', 'unique:parts,sku'],
            'name' => ['required', 'string', 'max:120'],
            'sale_price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'Já existe uma peça com este código (SKU).',
            'sale_price_cents.max' => 'O preço não pode passar de R$ 1.000.000,00.',
        ];
    }
}
