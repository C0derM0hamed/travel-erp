<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends ApiController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return response()->json(['data' => User::orderBy('id')->get()->map(fn (User $user) => $this->userPayload($user))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'accountant', 'sales', 'auditor'])],
            'role_label' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:10'],
        ]);

        $user = User::create($data + ['must_change_password' => true, 'is_active' => true]);
        app(ActivityLogger::class)->log('user.created', $user, ['email' => $user->email], $request->user()->id);

        return response()->json($this->userPayload($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['sometimes', 'required', Rule::in(['admin', 'accountant', 'sales', 'auditor'])],
            'role_label' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
            'must_change_password' => ['sometimes', 'boolean'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['must_change_password'] = true;
        }

        $user->update($data);
        app(ActivityLogger::class)->log('user.updated', $user, array_keys($data), $request->user()->id);

        return response()->json($this->userPayload($user->fresh()));
    }
}
