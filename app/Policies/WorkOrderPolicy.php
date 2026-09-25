<?php

namespace App\Policies;

use App\Models\User;

class WorkOrderPolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
