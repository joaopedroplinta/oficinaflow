<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', WorkOrder::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('active', true)],
            'device_id' => ['required', 'integer', Rule::exists('devices', 'id')
                ->where('customer_id', $this->integer('customer_id'))
                ->where('active', true)],
            'reported_problem' => ['required', 'string', 'max:2000'],
            'intake_condition' => ['required', 'string', 'max:2000'],
            'accessories' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'Cliente não encontrado ou inativo.',
            'device_id.exists' => 'Equipamento não encontrado, inativo ou de outro cliente.',
        ];
    }
}
