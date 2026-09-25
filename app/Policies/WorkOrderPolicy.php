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
}
