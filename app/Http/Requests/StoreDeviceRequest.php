<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Device::class);
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:60'],
            'brand' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->route('customer')->active
                || $validator->errors()->add('customer', 'Este cliente está inativo. Reative o cliente para cadastrar equipamentos.'),
        ];
    }
}
