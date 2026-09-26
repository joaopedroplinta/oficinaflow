<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\CreatePurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelPurchaseOrderRequest;
use App\Http\Requests\ReceivePurchaseOrderRequest;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $request->validate([
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'supplier_id' => ['nullable', 'integer'],
            'number' => ['nullable', 'integer', 'min:1'],
        ]);

        $orders = PurchaseOrder::query()
            ->with('supplier')
            ->addSelect(['purchase_orders.*', 'total_cents' => DB::table('purchase_order_items')
                ->selectRaw('COALESCE(SUM(quantity * unit_cost_cents), 0)')->whereColumn('purchase_order_id', 'purchase_orders.id')])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('number'), fn ($q) => $q->where('number', $request->integer('number')))
            ->orderByDesc('id')
            ->paginate(20);

        return PurchaseOrderResource::collection($orders);
    }

    public function store(StorePurchaseOrderRequest $request, CreatePurchaseOrder $create): JsonResponse
    {
        $order = $create->handle($request->validated(), $request->user());

        return (new PurchaseOrderResource($this->detail($order)))->response()->setStatusCode(201);
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        return new PurchaseOrderResource($this->detail($purchaseOrder));
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, ReceivePurchaseOrder $receive): PurchaseOrderResource
    {
        $receive->handle($purchaseOrder, $request->validated('items'), $request->user());

        return new PurchaseOrderResource($this->detail($purchaseOrder));
    }

    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrder $cancel): PurchaseOrderResource
    {
        $cancel->handle($purchaseOrder, $request->validated('reason'), $request->user());

        return new PurchaseOrderResource($this->detail($purchaseOrder));
    }

    /** Pedido com fornecedor, autor e itens (peça, quantidade recebida por soma dos movimentos vinculados). */
    private function detail(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh()->load(['supplier', 'creator', 'items' => fn ($q) => $q->with('part')->withSum('receipts as received_quantity', 'quantity')]);
    }
}
