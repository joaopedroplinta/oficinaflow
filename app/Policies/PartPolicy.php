<?php

namespace App\Policies;

use App\Models\User;

class PartPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
}
