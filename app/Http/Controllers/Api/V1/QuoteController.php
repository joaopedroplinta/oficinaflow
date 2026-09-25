<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quotes\CreateQuoteDraft;
use App\Actions\Quotes\SaveQuoteDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveQuoteDraftRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class QuoteController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder, CreateQuoteDraft $create): JsonResponse
    {
        Gate::authorize('create', Quote::class);

        return (new QuoteResource($create->handle($workOrder, $request->user())))->response()->setStatusCode(201);
    }

    public function updateDraft(SaveQuoteDraftRequest $request, Quote $quote, SaveQuoteDraft $save): QuoteResource
    {
        return new QuoteResource($save->handle($quote, $request->validated()));
    }
}
