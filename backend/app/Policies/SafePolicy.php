<?php

namespace App\Policies;

use App\Models\Safe;
use App\Models\User;
use App\Policies\Concerns\ChecksOfficeAccess;

class SafePolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Safe $safe): bool
    {
        return $this->viewAny($user) && $this->sameOffice($user, $safe->office_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant'], true);
    }

    public function update(User $user, Safe $safe): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant'], true)
            && $this->sameOffice($user, $safe->office_id);
    }

    public function toggle(User $user, Safe $safe): bool
    {
        return $this->update($user, $safe);
    }
}
