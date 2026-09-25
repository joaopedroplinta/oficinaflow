<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\TechnicianResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

// Só id e nome, para o seletor de responsável técnico.
class TechnicianController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        return TechnicianResource::collection(
            User::where('role', Role::Technician)->where('active', true)->orderBy('name')->get(),
        );
    }
}
