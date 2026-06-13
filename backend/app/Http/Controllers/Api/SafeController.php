<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreSafeRequest;
use App\Http\Requests\UpdateSafeRequest;
use App\Models\JournalEntry;
use App\Models\Safe;
use App\Services\ActivityLogger;
use App\Services\SafeManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SafeController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Safe::class);

        $query = Safe::with('account')->orderBy('id');

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        if ($request->filled('active') && $request->active !== 'all') {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%$search%");
        }

        return $this->paginatedResponse($request, $query, fn (Safe $safe) => $this->safeDetailPayload($safe));
    }

    public function store(StoreSafeRequest $request, SafeManagementService $service): JsonResponse
    {
        $safe = $service->create($request->validated());
        app(ActivityLogger::class)->log('safe.created', $safe, ['name' => $safe->name, 'type' => $safe->type], $request->user()->id);

        return response()->json($this->safeDetailPayload($safe), 201);
    }

    public function update(UpdateSafeRequest $request, Safe $safe, SafeManagementService $service): JsonResponse
    {
        $safe = $service->update($safe, $request->validated());
        app(ActivityLogger::class)->log('safe.updated', $safe, ['name' => $safe->name], $request->user()->id);

        return response()->json($this->safeDetailPayload($safe));
    }

    public function toggle(Request $request, Safe $safe, SafeManagementService $service): JsonResponse
    {
        Gate::authorize('toggle', $safe);
        $safe = $service->toggleActive($safe);
        app(ActivityLogger::class)->log('safe.toggled', $safe, ['is_active' => $safe->is_active], $request->user()->id);

        return response()->json($this->safeDetailPayload($safe));
    }

    private function safeDetailPayload(Safe $safe): array
    {
        return $this->safePayload($safe) + [
            'balance' => $this->accounting->safeBalance($safe->id),
            'account_code' => $safe->account?->code,
            'movements' => $safe->account
                ? JournalEntry::with('account')->where('account_id', $safe->account->id)->orderByDesc('entry_date')->orderByDesc('id')->take(10)->get()->map(fn (JournalEntry $journal) => $this->journalPayload($journal))
                : [],
        ];
    }
}
