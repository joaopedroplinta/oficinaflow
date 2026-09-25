<?php

namespace App\Http\Requests;

use App\Models\Part;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Part::class);
    }

    // SKU e saldo não editam por aqui.
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'sale_price_cents' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
