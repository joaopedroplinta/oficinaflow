<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\ConsumeReservations;
use App\Actions\Inventory\ReserveParts;
use App\Actions\Inventory\StartRepair;
use App\Actions\WorkOrders\CancelWorkOrder;
use App\Actions\WorkOrders\CompleteRepair;
use App\Actions\WorkOrders\DeliverWorkOrder;
use App\Actions\WorkOrders\OpenReturn;
use App\Actions\WorkOrders\OpenWorkOrder;
use App\Actions\WorkOrders\RecordDiagnosis;
use App\Actions\WorkOrders\StartDiagnosis;
use App\Enums\WorkOrderKind;
use App\Enums\WorkOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelWorkOrderRequest;
use App\Http\Requests\CompleteRepairRequest;
use App\Http\Requests\ConsumePartsRequest;
use App\Http\Requests\OpenReturnRequest;
use App\Http\Requests\RecordDiagnosisRequest;
use App\Http\Requests\ReservePartsRequest;
use App\Http\Requests\StartRepairRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Resources\PartReservationResource;
use App\Http\Resources\WorkOrderDetailResource;
use App\Http\Resources\WorkOrderEventResource;
use App\Http\Resources\WorkOrderResource;
use App\Models\Customer;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
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
            'kind' => ['nullable', Rule::enum(WorkOrderKind::class)],
            'number' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'string', 'max:100'],
            'awaiting_parts' => ['nullable', 'boolean'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'technician_id' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
            'scope' => ['nullable', Rule::in(['open', 'closed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'customer'])],
            'include_summary' => ['nullable', 'boolean'],
        ]);

        $orders = WorkOrder::query()
            ->withAwaitingParts()
            ->with('customer', 'origin', 'technician')
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('awaiting_parts'), fn ($q) => $q->awaitingParts())
            ->when($request->filled('number'), fn ($q) => $q->where('number', $request->integer('number')))
            ->when($request->filled('customer'), function ($q) use ($request) {
                $term = '%'.addcslashes($request->string('customer')->trim(), '%_\\').'%';
                $q->whereHas('customer', fn ($c) => $c->where('name', 'ilike', $term));
            })
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->filled('technician_id'), fn ($q) => $q->where('assigned_technician_id', $request->integer('technician_id')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_technician_id', $request->user()->id))
            ->when($request->input('scope') === 'open', fn ($q) => $q->whereNotIn('status', ['delivered', 'canceled']))
            ->when($request->input('scope') === 'closed', fn ($q) => $q->whereIn('status', ['delivered', 'canceled']))
            ->when($request->filled('from'), fn ($q) => $q->where('received_at', '>=', CarbonImmutable::parse($request->input('from'), 'America/Sao_Paulo')->startOfDay()->utc()))
            ->when($request->filled('to'), fn ($q) => $q->where('received_at', '<', CarbonImmutable::parse($request->input('to'), 'America/Sao_Paulo')->addDay()->startOfDay()->utc()));

        if ($request->input('sort') === 'customer') {
            $orders->orderBy(Customer::select('name')->whereColumn('customers.id', 'work_orders.customer_id'));
        }
        $orders->orderBy('id', $request->input('sort') === 'oldest' ? 'asc' : 'desc');
        $result = WorkOrderResource::collection($orders->paginate(20));
        if ($request->boolean('include_summary')) {
            $base = WorkOrder::query()->when($request->boolean('mine'), fn ($q) => $q->where('assigned_technician_id', $request->user()->id));
            $result->additional(['summary' => [
                'awaiting_approval' => (clone $base)->where('status', 'awaiting_approval')->count(),
                'awaiting_parts' => (clone $base)->awaitingParts()->count(),
                'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
                'ready_for_pickup' => (clone $base)->where('status', 'ready_for_pickup')->count(),
            ]]);
        }

        return $result;
    }

    public function store(StoreWorkOrderRequest $request, OpenWorkOrder $open): JsonResponse
    {
        $order = $open->handle($request->safe()->except('customer_id'), $request->user());

        return (new WorkOrderResource($order))->response()->setStatusCode(201);
    }

    public function show(WorkOrder $workOrder): WorkOrderDetailResource
    {
        Gate::authorize('view', $workOrder);

        return new WorkOrderDetailResource($workOrder->load(['customer', 'technician', 'origin', 'returns']));
    }

    /** Garantia ou retrabalho de uma ordem entregue: abre uma nova ordem vinculada. */
    public function openReturn(OpenReturnRequest $request, WorkOrder $workOrder, OpenReturn $open): JsonResponse
    {
        $order = $open->handle($workOrder, WorkOrderKind::from($request->validated('kind')), $request->safe()->except('kind'), $request->user());

        return (new WorkOrderResource($order))->response()->setStatusCode(201);
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

    public function reserveParts(ReservePartsRequest $request, WorkOrder $workOrder, ReserveParts $reserve): AnonymousResourceCollection
    {
        return PartReservationResource::collection($reserve->handle($workOrder, $request->integer('quote_id'), $request->user(), $request->boolean('partial')));
    }

    /** Baixa as reservas ativas com o reparo em andamento (o que foi reservado depois de um início parcial). */
    public function consumeParts(ConsumePartsRequest $request, WorkOrder $workOrder, ConsumeReservations $consume): AnonymousResourceCollection
    {
        return PartReservationResource::collection($consume->handle($workOrder, $request->integer('quote_id'), $request->user()));
    }

    public function startRepair(StartRepairRequest $request, WorkOrder $workOrder, StartRepair $start): WorkOrderDetailResource
    {
        return new WorkOrderDetailResource($start->handle($workOrder, $request->integer('quote_id'), $request->user(), $request->boolean('partial_start'))->load(['customer', 'technician']));
    }

    public function completeRepair(CompleteRepairRequest $request, WorkOrder $workOrder, CompleteRepair $complete): WorkOrderDetailResource
    {
        return new WorkOrderDetailResource($complete->handle($workOrder, $request->validated('work_performed'), $request->user())->load(['customer', 'technician']));
    }

    public function deliver(Request $request, WorkOrder $workOrder, DeliverWorkOrder $deliver): WorkOrderDetailResource
    {
        Gate::authorize('deliver', $workOrder);

        return new WorkOrderDetailResource($deliver->handle($workOrder, $request->user())->load(['customer', 'technician']));
    }

    public function cancel(CancelWorkOrderRequest $request, WorkOrder $workOrder, CancelWorkOrder $cancel): WorkOrderDetailResource
    {
        return new WorkOrderDetailResource($cancel->handle($workOrder, $request->validated('reason'), $request->user(), collect($request->validated('parts', []))->mapWithKeys(fn ($p) => [$p['part_id'] => ['action' => $p['action'], 'quantity' => (int) $p['quantity']]])->all())->load(['customer', 'technician']));
    }
}
