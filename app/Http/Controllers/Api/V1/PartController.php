<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartResource;
use App\Models\Part;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PartController extends Controller
{
    // ponytail: peças ativas sem paginação nem busca (catálogo pequeno no MVP); o #16 troca por busca paginada.
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Part::class);

        return PartResource::collection(Part::where('active', true)->orderBy('name')->orderBy('id')->limit(500)->get());
    }
}
