<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;

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
}
