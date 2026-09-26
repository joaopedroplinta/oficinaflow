<?php

namespace App\Policies;

use App\Models\User;

// Compras são do atendimento (PLANO §13.3): o técnico não vê fornecedor nem custo.
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }
}
