<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\OfficeContext;

trait ChecksOfficeAccess
{
    protected function sameOffice(User $user, ?int $officeId): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        return $officeId !== null && (int) $user->office_id === (int) $officeId;
    }

    protected function currentOfficeId(): ?int
    {
        return app(OfficeContext::class)->id();
    }
}
