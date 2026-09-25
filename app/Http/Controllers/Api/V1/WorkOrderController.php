<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WorkOrders\OpenWorkOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Resources\WorkOrderResource;
use Illuminate\Http\JsonResponse;

class WorkOrderController extends Controller
{
    public function store(StoreWorkOrderRequest $request, OpenWorkOrder $open): JsonResponse
    {
        $order = $open->handle($request->safe()->except('customer_id'), $request->user());

        return (new WorkOrderResource($order))->response()->setStatusCode(201);
    }
}
