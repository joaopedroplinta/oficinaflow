<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quotes\CreateAddendum;
use App\Actions\Quotes\CreateQuoteDraft;
use App\Actions\Quotes\IssueAddendum;
use App\Actions\Quotes\IssueQuote;
use App\Actions\Quotes\RecordQuoteDecision;
use App\Actions\Quotes\ReviseQuote;
use App\Actions\Quotes\SaveQuoteDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordQuoteDecisionRequest;
use App\Http\Requests\ReviseQuoteRequest;
use App\Http\Requests\SaveQuoteDraftRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class QuoteController extends Controller
{
    /** Histórico de versões da ordem, da mais nova para a mais antiga, com itens e decisões. */
    public function index(WorkOrder $workOrder): AnonymousResourceCollection
    {
        Gate::authorize('view', $workOrder);

        return QuoteResource::collection(
            $workOrder->quotes()->with(['items.part', 'decision.recorder'])->orderBy('kind')->orderByDesc('version')->get(),
        );
    }

    public function store(Request $request, WorkOrder $workOrder, CreateQuoteDraft $create): JsonResponse
    {
        Gate::authorize('create', Quote::class);

        return (new QuoteResource($create->handle($workOrder, $request->user())))->response()->setStatusCode(201);
    }

    /** Adendo ao orçamento aprovado, com o reparo em andamento. */
    public function storeAddendum(Request $request, WorkOrder $workOrder, CreateAddendum $create): JsonResponse
    {
        Gate::authorize('create', Quote::class);

        return (new QuoteResource($create->handle($workOrder, $request->user())))->response()->setStatusCode(201);
    }

    public function updateDraft(SaveQuoteDraftRequest $request, Quote $quote, SaveQuoteDraft $save): QuoteResource
    {
        return new QuoteResource($save->handle($quote, $request->validated()));
    }

    public function issue(Request $request, Quote $quote, IssueQuote $issue): QuoteResource
    {
        Gate::authorize('issue', $quote);

        return new QuoteResource($quote->isAddendum() ? app(IssueAddendum::class)->handle($quote, $request->user()) : $issue->handle($quote, $request->user()));
    }

    public function decide(RecordQuoteDecisionRequest $request, Quote $quote, RecordQuoteDecision $record): QuoteResource
    {
        return new QuoteResource($record->handle($quote, $request->validated(), $request->user()));
    }

    public function revise(ReviseQuoteRequest $request, WorkOrder $workOrder, ReviseQuote $revise): JsonResponse
    {
        $quote = $revise->handle($workOrder, $request->integer('quote_id'), $request->user());

        return (new QuoteResource($quote))->response()->setStatusCode(201);
    }
}
