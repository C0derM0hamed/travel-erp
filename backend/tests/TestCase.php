<?php

namespace Tests;

use App\Models\Office;
use App\Models\User;
use App\Support\OfficeContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AuthenticateSession::class);
    }

    protected function withOfficeContext(?int $officeId = null): self
    {
        $officeId ??= Office::where('office_code', 'MAIN')->value('id') ?? 1;
        app(OfficeContext::class)->setOfficeId((int) $officeId);

        return $this;
    }

    protected function actingAsWithOffice(User $user): self
    {
        if ($user->office_id) {
            $this->withOfficeContext($user->office_id);
        } elseif ($user->role === 'super_admin') {
            $this->withOfficeContext();
        }

        return $this->actingAs($user);
    }
}
