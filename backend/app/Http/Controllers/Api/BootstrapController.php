<?php

namespace App\Http\Controllers\Api;

use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $users = $request->user()->role === 'admin'
            ? User::orderBy('id')->get()->map(fn (User $user) => $this->userPayload($user))
            : [$this->userPayload($request->user())];

        return response()->json([
            'user' => $this->userPayload($request->user()),
            'users' => $users,
            'services' => Service::orderBy('id')->get(),
            'safes' => Safe::with('account')->orderBy('id')->get()->map(fn (Safe $safe) => $this->safePayload($safe) + [
                'balance' => $this->accounting->safeBalance($safe->id),
            ]),
            'metrics' => $this->metricsPayload(),
        ]);
    }
}
