<?php

namespace App\Support;

use App\Models\Office;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

class OfficeContext
{
    private ?int $officeId = null;

    private bool $scopeDisabled = false;

    public function setOfficeId(?int $officeId): void
    {
        $this->officeId = $officeId;
    }

    public function id(): ?int
    {
        return $this->officeId;
    }

    public function requireId(): int
    {
        if ($this->officeId === null) {
            throw new AuthorizationException('Office context is required.');
        }

        return $this->officeId;
    }

    public function office(): ?Office
    {
        return $this->officeId ? Office::find($this->officeId) : null;
    }

    public function user(): ?User
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user;
    }

    public function isSuperAdmin(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function withoutScope(callable $callback): mixed
    {
        $previous = $this->scopeDisabled;
        $this->scopeDisabled = true;

        try {
            return $callback();
        } finally {
            $this->scopeDisabled = $previous;
        }
    }

    public function isScopeDisabled(): bool
    {
        return $this->scopeDisabled;
    }

    public function canAccessOffice(int $officeId): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if (in_array($user->role, ['super_admin', 'admin'], true)) {
            return true;
        }

        return (int) $user->office_id === $officeId;
    }
}
