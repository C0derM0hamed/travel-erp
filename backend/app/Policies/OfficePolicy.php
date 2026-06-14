<?php

namespace App\Policies;

use App\Models\Office;
use App\Models\User;

class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function view(User $user, Office $office): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function update(User $user, Office $office): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }

    public function delete(User $user, Office $office): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }
}
