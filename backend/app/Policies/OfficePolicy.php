<?php

namespace App\Policies;

use App\Models\Office;
use App\Models\User;

class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    public function view(User $user, Office $office): bool
    {
        return $user->role === 'super_admin';
    }

    public function create(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    public function update(User $user, Office $office): bool
    {
        return $user->role === 'super_admin';
    }

    public function delete(User $user, Office $office): bool
    {
        return $user->role === 'super_admin';
    }
}
