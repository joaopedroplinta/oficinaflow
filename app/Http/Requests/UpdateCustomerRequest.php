<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Customer::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'required', 'string', 'min:8', 'max:20', 'regex:/^[0-9()+\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:160'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
