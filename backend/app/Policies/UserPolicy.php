<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksOfficeAccess;

class UserPolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function update(User $user, User $target): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->role === 'admin' && $this->sameOffice($user, $target->office_id);
    }
}
