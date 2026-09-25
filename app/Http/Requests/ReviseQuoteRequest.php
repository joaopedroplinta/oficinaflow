<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class ReviseQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('revise', Quote::class);
    }

    // A versão que a tela mostrava: comparada com a atual só depois do lock da ordem.
    public function rules(): array
    {
        return ['quote_id' => ['required', 'integer']];
    }
}
