<?php

namespace App\Http\Requests;

use App\Enums\WorkOrderKind;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('openReturn', $this->route('workOrder') ?? WorkOrder::class);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in([WorkOrderKind::Warranty->value, WorkOrderKind::Rework->value])],
            'reported_problem' => ['required', 'string', 'max:2000'],
            'intake_condition' => ['required', 'string', 'max:2000'],
            'accessories' => ['required', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['kind.required' => 'Escolha entre garantia e retrabalho.', 'kind.in' => 'Escolha entre garantia e retrabalho.'];
    }
}
