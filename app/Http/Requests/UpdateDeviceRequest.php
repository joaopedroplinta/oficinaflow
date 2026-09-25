<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Device::class);
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'required', 'string', 'max:60'],
            'brand' => ['sometimes', 'required', 'string', 'max:60'],
            'model' => ['sometimes', 'required', 'string', 'max:80'],
            'serial_number' => ['nullable', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
