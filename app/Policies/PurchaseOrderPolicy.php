<?php

namespace App\Policies;

use App\Models\User;

// Compras são do atendimento (PLANO §13.3).
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function receive(User $user): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user): bool
    {
        return $user->isAdmin();
    }
}
