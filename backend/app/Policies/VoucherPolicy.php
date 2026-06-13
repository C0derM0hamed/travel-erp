<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;
use App\Policies\Concerns\ChecksOfficeAccess;

class VoucherPolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $this->viewAny($user) && $this->sameOffice($user, $voucher->office_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'accountant'], true);
    }

    public function void(User $user, Voucher $voucher): bool
    {
        return $this->create($user) && $this->sameOffice($user, $voucher->office_id);
    }
}
