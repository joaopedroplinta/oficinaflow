<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SupplierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Supplier::class);

        $request->validate(['q' => 'nullable|string|max:100', 'active' => 'nullable|boolean']);

        $suppliers = Supplier::query()
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($request->string('q')->trim(), '%_\\').'%'))
            ->when($request->filled('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('name')->orderBy('id')
            ->paginate(20);

        return SupplierResource::collection($suppliers);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        return (new SupplierResource(Supplier::create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Supplier $supplier): SupplierResource
    {
        Gate::authorize('viewAny', Supplier::class);

        return new SupplierResource($supplier);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $supplier->update($request->validated());

        return new SupplierResource($supplier);
    }
}
