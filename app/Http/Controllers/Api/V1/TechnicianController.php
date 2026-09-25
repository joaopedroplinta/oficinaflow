<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

// Só id e nome, para o seletor de responsável técnico.
class TechnicianController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return response()->json([
            'data' => User::where('role', Role::Technician)->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
