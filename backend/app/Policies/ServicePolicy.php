<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    public function update(User $user, Service $service): bool
    {
        return in_array($user->role, ['super_admin', 'admin'], true);
    }
}
