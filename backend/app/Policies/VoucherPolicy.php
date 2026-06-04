<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Voucher;

class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'accountant'], true);
    }

    public function void(User $user, Voucher $voucher): bool
    {
        return $this->create($user);
    }
}
