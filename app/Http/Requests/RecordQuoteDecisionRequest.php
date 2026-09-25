<?php

namespace App\Http\Requests;

use App\Enums\DecisionChannel;
use App\Enums\QuoteDecisionType;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordQuoteDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', Quote::class);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(QuoteDecisionType::class)],
            'customer_name' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::enum(DecisionChannel::class)],
            // ISO 8601 com fuso: um horário sem offset seria lido como UTC e gravado deslocado.
            'decided_at' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'decided_at.before_or_equal' => 'A data da decisão não pode estar no futuro.',
            'decided_at.regex' => 'Informe a data e a hora com fuso horário (ex.: 2026-09-25T14:00:00-03:00).',
        ];
    }
}
