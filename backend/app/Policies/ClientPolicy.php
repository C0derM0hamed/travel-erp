<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ChecksOfficeAccess;

class ClientPolicy
{
    use ChecksOfficeAccess;

    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant', 'auditor'], true);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->viewAny($user) && $this->sameOffice($user, $client->office_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant'], true);
    }

    public function update(User $user, Client $client): bool
    {
        return in_array($user->role, ['super_admin', 'admin', 'sales', 'accountant'], true)
            && $this->sameOffice($user, $client->office_id);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    public function hide(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }
}
