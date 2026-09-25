<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordDiagnosis', $this->route('workOrder') ?? WorkOrder::class);
    }

    public function rules(): array
    {
        return [
            'diagnosis' => ['required', 'string', 'max:4000'],
            'assigned_technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')
                ->where('role', Role::Technician->value)
                ->where('active', true)],
        ];
    }

    public function messages(): array
    {
        return ['assigned_technician_id.exists' => 'Escolha um técnico ativo.'];
    }
}
