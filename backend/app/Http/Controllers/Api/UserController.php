<?php

namespace App\Http\Controllers\Api;

use App\Models\Office;
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

        $query = User::with('office')->orderBy('id');
        $authUser = auth()->user();

        if ($authUser->role === 'admin') {
            $query->where('office_id', $authUser->office_id);
        }

        return response()->json(['data' => $query->get()->map(fn (User $user) => $this->userPayload($user))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $authUser = $request->user();
        $allowedRoles = $authUser->role === 'super_admin'
            ? ['super_admin', 'admin', 'accountant', 'sales', 'auditor']
            : ['admin', 'accountant', 'sales', 'auditor'];

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::in($allowedRoles)],
            'role_label' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:10'],
            'office_id' => ['nullable', 'exists:offices,id'],
        ]);

        if (in_array($authUser->role, ['super_admin', 'admin'], true)) {
            if (($data['role'] ?? '') === 'super_admin') {
                $data['office_id'] = null;
            } else {
                $data['office_id'] = $data['office_id'] ?? $authUser->office_id;
            }
        }

        if (($data['role'] ?? '') !== 'super_admin' && empty($data['office_id'])) {
            return response()->json(['message' => 'يجب تحديد المكتب للمستخدم'], 422);
        }

        $user = User::create($data + ['must_change_password' => true, 'is_active' => true]);
        app(ActivityLogger::class)->log('user.created', $user, ['email' => $user->email], $request->user()->id);

        return response()->json($this->userPayload($user->fresh(['office'])), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $authUser = $request->user();
        $allowedRoles = $authUser->role === 'super_admin'
            ? ['super_admin', 'admin', 'accountant', 'sales', 'auditor']
            : ['admin', 'accountant', 'sales', 'auditor'];

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['sometimes', 'required', Rule::in($allowedRoles)],
            'role_label' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'string', 'max:10'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'is_active' => ['sometimes', 'boolean'],
            'must_change_password' => ['sometimes', 'boolean'],
        ]);

        if (in_array($authUser->role, ['super_admin', 'admin'], true)) {
            if (isset($data['role']) && $data['role'] === 'super_admin') {
                $data['office_id'] = null;
            }
        }

        if (isset($data['role']) && ($data['role'] ?? $user->role) !== 'super_admin') {
            if (in_array($authUser->role, ['super_admin', 'admin'], true) && array_key_exists('office_id', $data) && empty($data['office_id'])) {
                return response()->json(['message' => 'يجب تحديد المكتب للمستخدم'], 422);
            }
        }

        if (array_key_exists('is_active', $data) && $data['is_active'] === false && $user->id === $authUser->id) {
            return response()->json(['message' => 'لا يمكن تعطيل حسابك الشخصي'], 422);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['must_change_password'] = true;
        }

        $user->update($data);
        app(ActivityLogger::class)->log('user.updated', $user, array_keys($data), $request->user()->id);

        return response()->json($this->userPayload($user->fresh(['office'])));
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user->update([
            'password' => $data['password'],
            'must_change_password' => true,
        ]);

        app(ActivityLogger::class)->log('user.password_reset', $user, ['email' => $user->email], $request->user()->id);

        return response()->json([
            'message' => 'تم إعادة تعيين كلمة المرور',
            'user' => $this->userPayload($user->fresh(['office'])),
        ]);
    }
}
