<?php

namespace App\Http\Controllers\Api;

use App\Models\Office;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\OfficeProvisioningService;
use App\Support\OfficeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OfficeController extends ApiController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Office::class);

        return response()->json([
            'data' => app(OfficeContext::class)->withoutScope(fn () => Office::orderBy('id')->get()->map(fn (Office $office) => $this->officePayload($office))),
        ]);
    }

    public function store(Request $request, OfficeProvisioningService $provisioning): JsonResponse
    {
        Gate::authorize('create', Office::class);

        $data = $request->validate([
            'office_code' => ['required', 'string', 'max:50', 'unique:offices,office_code'],
            'office_name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $office = $provisioning->createOffice($data);
        app(ActivityLogger::class)->log('office.created', $office, ['office_code' => $office->office_code], $request->user()->id);

        return response()->json($this->officePayload($office), 201);
    }

    public function update(Request $request, Office $office): JsonResponse
    {
        Gate::authorize('update', $office);

        $data = $request->validate([
            'office_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('offices', 'office_code')->ignore($office->id)],
            'office_name' => ['sometimes', 'required', 'string', 'max:255'],
            'logo' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $office->update($data);
        app(ActivityLogger::class)->log('office.updated', $office, array_keys($data), $request->user()->id);

        return response()->json($this->officePayload($office->fresh()));
    }

    public function switchOffice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'office_id' => ['required', 'exists:offices,id'],
        ]);

        $user = $request->user();
        $office = Office::findOrFail($data['office_id']);

        if ($user->role !== 'super_admin' && (int) $user->office_id !== (int) $office->id) {
            abort(403);
        }

        if (! $office->is_active && $user->role !== 'super_admin') {
            return response()->json(['message' => 'المكتب غير مفعل'], 403);
        }

        $request->session()->put('current_office_id', (int) $office->id);
        app(OfficeContext::class)->setOfficeId((int) $office->id);

        return response()->json([
            'office' => $this->officePayload($office),
            'user' => $this->userPayload($user),
        ]);
    }

    protected function officePayload(Office $office): array
    {
        return [
            'id' => $office->id,
            'office_code' => $office->office_code,
            'office_name' => $office->office_name,
            'logo' => $office->logo,
            'is_active' => (bool) $office->is_active,
            'users_count' => User::where('office_id', $office->id)->count(),
        ];
    }
}
