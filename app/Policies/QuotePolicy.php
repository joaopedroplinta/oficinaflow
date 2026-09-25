<?php

namespace App\Policies;

use App\Models\User;

// Orçamento é do atendimento (PLANO.md §8); técnico só consulta.
class QuotePolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }
}
