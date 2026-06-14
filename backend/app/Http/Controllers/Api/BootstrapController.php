<?php

namespace App\Http\Controllers\Api;

use App\Models\Office;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Support\OfficeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $context = app(OfficeContext::class);

        $usersQuery = User::query();
        if ($user->role === 'admin') {
            $usersQuery->where('office_id', $user->office_id);
        } elseif ($user->role !== 'super_admin') {
            $usersQuery->where('id', $user->id);
        }

        $users = in_array($user->role, ['super_admin', 'admin'], true)
            ? $usersQuery->orderBy('id')->get()->map(fn (User $u) => $this->userPayload($u))
            : [$this->userPayload($user)];

        $offices = $context->withoutScope(function () use ($user) {
            // Only super_admin can see all offices (for switching)
            if ($user->role === 'super_admin') {
                return Office::orderBy('id')->get()->map(fn (Office $office) => $this->officePayload($office));
            }

            return $user->office_id
                ? Office::whereKey($user->office_id)->get()->map(fn (Office $office) => $this->officePayload($office))
                : collect();
        });

        return response()->json([
            'user' => $this->userPayload($user->load('office')),
            'users' => $users,
            'offices' => $offices,
            'current_office' => $context->office() ? $this->officePayload($context->office()) : null,
            'services' => Service::orderBy('id')->get(),
            'safes' => Safe::with('account')->orderBy('id')->get()->map(fn (Safe $safe) => $this->safePayload($safe) + [
                'balance' => $this->accounting->safeBalance($safe->id),
            ]),
            'metrics' => $this->metricsPayload(),
        ]);
    }
}
