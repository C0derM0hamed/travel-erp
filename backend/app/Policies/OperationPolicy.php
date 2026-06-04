<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;

class OperationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Operation $operation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'sales'], true);
    }

    public function cancel(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['admin', 'accountant'], true);
    }

    public function updateStatus(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['admin', 'sales', 'accountant'], true);
    }

    public function update(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['admin', 'sales'], true);
    }
}
