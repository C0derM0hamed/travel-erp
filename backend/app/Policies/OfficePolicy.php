<?php

namespace App\Policies;

use App\Models\Office;
use App\Models\User;

class OfficePolicy
{
    public function viewAny(User $user): bool
    {
        // Only super_admin can view all offices
        return $user->role === 'super_admin';
    }

    public function view(User $user, Office $office): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->role === 'admin' && (int) $user->office_id === (int) $office->id;
    }

    public function create(User $user): bool
    {
        // Only super_admin can create new offices
        return $user->role === 'super_admin';
    }

    public function update(User $user, Office $office): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $user->role === 'admin' && (int) $user->office_id === (int) $office->id;
    }

    public function delete(User $user, Office $office): bool
    {
        // Only super_admin can delete offices
        return $user->role === 'super_admin';
    }
}
