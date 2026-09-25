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
            'decided_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return ['decided_at.before_or_equal' => 'A data da decisão não pode estar no futuro.'];
    }
}
