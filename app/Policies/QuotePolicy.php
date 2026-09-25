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

    public function issue(User $user): bool
    {
        return $user->isAdmin();
    }

    public function decide(User $user): bool
    {
        return $user->isAdmin();
    }

    public function revise(User $user): bool
    {
        return $user->isAdmin();
    }

    // Falar com o cliente é do atendimento (PLANO §13.1).
    public function deliver(User $user): bool
    {
        return $user->isAdmin();
    }
}
