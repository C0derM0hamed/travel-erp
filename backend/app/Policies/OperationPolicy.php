<?php

namespace App\Policies;

use App\Models\Operation;
use App\Models\User;
use App\Policies\Concerns\ChecksOfficeAccess;

class OperationPolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Operation $operation): bool
    {
        return $this->viewAny($user) && $this->sameOffice($user, $operation->office_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales'], true);
    }

    public function cancel(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant'], true)
            && $this->sameOffice($user, $operation->office_id);
    }

    public function updateStatus(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant'], true)
            && $this->sameOffice($user, $operation->office_id);
    }

    public function update(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales'], true)
            && $this->sameOffice($user, $operation->office_id);
    }

    public function hide(User $user, Operation $operation): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant'], true)
            && $this->sameOffice($user, $operation->office_id);
    }

    public function restore(User $user, Operation $operation): bool
    {
        return $this->hide($user, $operation);
    }
}
