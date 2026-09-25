<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WorkOrders\OpenWorkOrder;
use App\Actions\WorkOrders\RecordDiagnosis;
use App\Actions\WorkOrders\StartDiagnosis;
use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordDiagnosisRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Resources\WorkOrderDetailResource;
use App\Http\Resources\WorkOrderEventResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(WorkOrderStatus::class)],
            'number' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'string', 'max:100'],
        ]);

        $orders = WorkOrder::query()
            ->with('customer')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('number'), fn ($q) => $q->where('number', $request->integer('number')))
            ->when($request->filled('customer'), function ($q) use ($request) {
                $term = '%'.addcslashes($request->string('customer')->trim(), '%_\\').'%';
                $q->whereHas('customer', fn ($c) => $c->where('name', 'ilike', $term));
            })
            ->orderByDesc('id')
            ->paginate(20);

        return WorkOrderResource::collection($orders);
    }

    public function store(StoreWorkOrderRequest $request, OpenWorkOrder $open): JsonResponse
    {
        $order = $open->handle($request->safe()->except('customer_id'), $request->user());

        return (new WorkOrderResource($order))->response()->setStatusCode(201);
    }

    public function show(WorkOrder $workOrder): WorkOrderDetailResource
    {
        Gate::authorize('view', $workOrder);

        return new WorkOrderDetailResource($workOrder->load(['customer', 'technician']));
    }

    public function events(WorkOrder $workOrder): AnonymousResourceCollection
    {
        Gate::authorize('view', $workOrder);

        return WorkOrderEventResource::collection($workOrder->events()->with('actor')->orderBy('id')->get());
    }

    public function startDiagnosis(Request $request, WorkOrder $workOrder, StartDiagnosis $start): WorkOrderDetailResource
    {
        Gate::authorize('startDiagnosis', $workOrder);

        return new WorkOrderDetailResource($start->handle($workOrder, $request->user()));
    }

    public function recordDiagnosis(RecordDiagnosisRequest $request, WorkOrder $workOrder, RecordDiagnosis $record): WorkOrderDetailResource
    {
        return new WorkOrderDetailResource($record->handle($workOrder, $request->validated(), $request->user()));
    }
}
