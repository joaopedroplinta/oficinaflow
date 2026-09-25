<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('completeRepair', $this->route('workOrder') ?? WorkOrder::class);
    }

    public function rules(): array
    {
        return ['work_performed' => ['required', 'string', 'min:3', 'max:2000']];
    }
}
