<?php

namespace App\Http\Requests;

use App\Models\Part;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
