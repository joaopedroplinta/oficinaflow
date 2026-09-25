<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quotes\SendQuote;
use App\Enums\DeliveryChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteDeliveryRequest;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;

class QuoteDeliveryController extends Controller
{
    public function store(StoreQuoteDeliveryRequest $request, Quote $quote, SendQuote $send): JsonResponse
    {
        $result = $send->handle($quote, DeliveryChannel::from($request->validated('channel')), $request->user());

        return response()->json(['data' => [
            'channel' => $result['channel'],
            'channel_label' => $result['channel']->label(),
            'recipient' => $result['recipient'],
            'url' => $result['url'] ?? null,
        ]], 201);
    }
}
