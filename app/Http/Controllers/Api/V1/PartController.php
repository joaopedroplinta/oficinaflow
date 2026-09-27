<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\ReceivePart;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReceivePartRequest;
use App\Http\Requests\StorePartRequest;
use App\Http\Requests\UpdatePartRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\PartResource;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PartController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Part::class);

        $request->validate([
            'q' => 'nullable|string|max:100',
            'active' => 'nullable|boolean',
            'unavailable' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);

        $parts = Part::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.addcslashes($request->string('q')->trim(), '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('sku', 'ilike', $term));
            })
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->boolean('active')))
            ->when($request->boolean('unavailable'), fn ($query) => $query->whereColumn('on_hand', '<=', 'reserved_quantity'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 20));

        return PartResource::collection($parts);
    }

    public function store(StorePartRequest $request): JsonResponse
    {
        $part = Part::create($request->validated())->refresh();

        return (new PartResource($part))->response()->setStatusCode(201);
    }

    public function show(Part $part): PartResource
    {
        Gate::authorize('viewAny', Part::class);

        return new PartResource($part);
    }

    public function update(UpdatePartRequest $request, Part $part): PartResource
    {
        $part->update($request->validated());

        return new PartResource($part);
    }

    public function receive(ReceivePartRequest $request, Part $part, ReceivePart $receive): PartResource
    {
        return new PartResource($receive->handle($part, $request->integer('quantity'), $request->input('reason'), $request->user()));
    }

    public function movements(Part $part): AnonymousResourceCollection
    {
        Gate::authorize('viewMovements', Part::class);

        return InventoryMovementResource::collection(
            $part->movements()->with('author')->orderByDesc('id')->paginate(20),
        );
    }
}
