<?php

namespace App\Policies;

use App\Models\User;

// Estoque é do atendimento (PLANO.md §8): o técnico consulta o catálogo, o admin escreve e vê movimentos.
class PartPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user): bool
    {
        return $user->isAdmin();
    }

    public function receive(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewMovements(User $user): bool
    {
        return $user->isAdmin();
    }
}
