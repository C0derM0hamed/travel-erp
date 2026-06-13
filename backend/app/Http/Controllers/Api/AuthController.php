<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Models\Office;
use App\Models\User;
use App\Support\OfficeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة']);
        }
        if (isset($user->is_active) && ! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'هذا المستخدم غير مفعل']);
        }

        if ($user->role !== 'super_admin') {
            $office = Office::find($user->office_id);
            if (! $office || ! $office->is_active) {
                throw ValidationException::withMessages(['email' => 'المكتب غير مفعل']);
            }
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $officeId = $user->role === 'super_admin'
            ? Office::where('is_active', true)->orderBy('id')->value('id')
            : $user->office_id;

        if ($officeId) {
            $request->session()->put('current_office_id', (int) $officeId);
            app(OfficeContext::class)->setOfficeId((int) $officeId);
        }

        return response()->json(['user' => $this->userPayload($user->fresh(['office']))]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح.']);
    }

    public function me(Request $request): JsonResponse
    {
        $this->applyOfficeContext($request);

        return response()->json(['user' => $this->userPayload($request->user()->load('office'))]);
    }

    private function applyOfficeContext(Request $request): void
    {
        $context = app(OfficeContext::class);
        if ($context->id() !== null) {
            return;
        }

        $user = $request->user();
        $officeId = $request->session()->get('current_office_id');

        if (! $officeId && $user->role !== 'super_admin') {
            $officeId = $user->office_id;
        }

        if (! $officeId && $user->role === 'super_admin') {
            $officeId = Office::where('is_active', true)->orderBy('id')->value('id');
        }

        if ($officeId) {
            $context->setOfficeId((int) $officeId);
        }
    }
}
