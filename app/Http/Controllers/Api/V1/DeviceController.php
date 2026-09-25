<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Customer;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DeviceController extends Controller
{
    public function index(Customer $customer): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Device::class);

        return DeviceResource::collection($customer->devices()->orderBy('id')->get());
    }

    public function store(StoreDeviceRequest $request, Customer $customer): JsonResponse
    {
        $device = $customer->devices()->create($request->validated());

        return (new DeviceResource($device))->response()->setStatusCode(201);
    }
}
