<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Supplier::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120', $this->uniqueIgnoringCase()],
            'contact' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** O banco tem o índice único em `lower(name)`; aqui vira mensagem em vez de 500. */
    private function uniqueIgnoringCase(): Closure
    {
        $ignore = $this->route('supplier');

        return fn ($attribute, $value, $fail) => Supplier::whereRaw('lower(name) = lower(?)', [$value])->whereKeyNot($ignore?->id)->exists() ? $fail('Já existe um fornecedor com esse nome.') : null;
    }
}
