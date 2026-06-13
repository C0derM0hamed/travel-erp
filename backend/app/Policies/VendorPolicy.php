<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;
use App\Policies\Concerns\ChecksOfficeAccess;

class VendorPolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $this->viewAny($user) && $this->sameOffice($user, $vendor->office_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant'], true);
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant'], true)
            && $this->sameOffice($user, $vendor->office_id);
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $this->update($user, $vendor);
    }
}
