<?php

namespace App\Policies;

use App\Models\SafeTransfer;
use App\Models\User;
use App\Policies\Concerns\ChecksOfficeAccess;

class SafeTransferPolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant', 'auditor'], true);
    }

    public function view(User $user, SafeTransfer $transfer): bool
    {
        return $this->viewAny($user) && $this->sameOffice($user, $transfer->office_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant'], true);
    }
}
