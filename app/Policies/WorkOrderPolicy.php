<?php

namespace App\Policies;

use App\Models\User;

class WorkOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    // Técnico e atendimento podem conduzir o diagnóstico (PLANO.md §8).
    public function startDiagnosis(User $user): bool
    {
        return true;
    }

    public function recordDiagnosis(User $user): bool
    {
        return true;
    }

    // Estoque é do atendimento (PLANO.md §8).
    public function reserveParts(User $user): bool
    {
        return $user->isAdmin();
    }

    // O técnico registra o início do reparo (PLANO.md §8); o atendimento também pode.
    public function startRepair(User $user): bool
    {
        return true;
    }

    // O técnico conclui o reparo; a entrega ao cliente é do atendimento (PLANO.md §8).
    public function completeRepair(User $user): bool
    {
        return true;
    }

    public function deliver(User $user): bool
    {
        return $user->isAdmin();
    }

    // Cancelar o serviço é do atendimento (PLANO.md §8).
    public function cancel(User $user): bool
    {
        return $user->isAdmin();
    }
}
