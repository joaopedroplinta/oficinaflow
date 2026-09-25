<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;

class ConsumePartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('consumeParts', $this->route('workOrder') ?? WorkOrder::class);
    }

    // A versão que a tela mostrava: comparada com a atual só depois do lock da ordem.
    public function rules(): array
    {
        return ['quote_id' => ['required', 'integer']];
    }
}
