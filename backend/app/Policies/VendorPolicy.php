<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'sales', 'accountant'], true);
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return in_array($user->role, ['admin', 'sales', 'accountant'], true);
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $this->update($user, $vendor);
    }
}
