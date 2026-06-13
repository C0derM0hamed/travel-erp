<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Services\Exports\ExportLabels;
use App\Support\OfficeContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewReports');

        $user = $request->user();
        $query = ActivityLog::with(['user', 'office'])->orderByDesc('id');

        if ($user->role !== 'super_admin') {
            $officeId = app(OfficeContext::class)->id() ?? $user->office_id;
            if ($officeId) {
                $query->where('office_id', $officeId);
            }
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }
        if ($request->filled('action') && $request->action !== 'all') {
            if ($request->action === 'operations') {
                $query->where('action', 'like', 'operation.%');
            } else {
                $query->where('action', $request->action);
            }
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('action', 'like', "%$search%")
                ->orWhere('payload', 'like', "%$search%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%"))
                ->orWhereHas('office', fn ($o) => $o->where('office_name', 'like', "%$search%")));
        }

        return $this->paginatedResponse($request, $query, fn (ActivityLog $log) => $this->activityLogPayload($log));
    }

    public function actions(): JsonResponse
    {
        Gate::authorize('viewReports');

        $user = auth()->user();
        $query = ActivityLog::query()->select('action')->distinct()->orderBy('action');

        if ($user->role !== 'super_admin') {
            $officeId = app(OfficeContext::class)->id() ?? $user->office_id;
            if ($officeId) {
                $query->where('office_id', $officeId);
            }
        }

        return response()->json([
            'data' => $query->pluck('action')->map(fn (string $action) => [
                'key' => $action,
                'label' => ExportLabels::activityAction($action),
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function activityLogPayload(ActivityLog $log): array
    {
        $payload = is_array($log->payload) ? $log->payload : [];

        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => ExportLabels::activityAction($log->action),
            'user_id' => $log->user_id,
            'user_name' => $log->user?->name ?? ($payload['user_name'] ?? null),
            'office_id' => $log->office_id,
            'office_name' => $log->office?->office_name ?? ($payload['office_name'] ?? null),
            'operation_ref' => $payload['ref'] ?? null,
            'details' => ExportLabels::activityDetails($log->action, $payload),
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'payload' => $log->payload,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
