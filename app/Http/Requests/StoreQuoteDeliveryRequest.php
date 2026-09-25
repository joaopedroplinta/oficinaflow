<?php

namespace App\Http\Requests;

use App\Enums\DeliveryChannel;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuoteDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deliver', Quote::class);
    }

    public function rules(): array
    {
        return ['channel' => ['required', Rule::enum(DeliveryChannel::class)]];
    }

    public function messages(): array
    {
        return ['channel.required' => 'Escolha o canal de envio.', 'channel.enum' => 'Escolha e-mail ou WhatsApp.'];
    }
}
