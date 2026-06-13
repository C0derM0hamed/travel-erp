<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreSafeTransferRequest;
use App\Models\SafeTransfer;
use App\Services\ActivityLogger;
use App\Services\SafeTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SafeTransferController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SafeTransfer::class);

        $query = SafeTransfer::with(['fromSafe', 'toSafe', 'creator'])->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('transfer_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('transfer_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('ref', 'like', "%$search%")
                ->orWhere('notes', 'like', "%$search%")
                ->orWhereHas('fromSafe', fn ($s) => $s->where('name', 'like', "%$search%"))
                ->orWhereHas('toSafe', fn ($s) => $s->where('name', 'like', "%$search%")));
        }

        return $this->paginatedResponse($request, $query, fn (SafeTransfer $transfer) => $this->transferPayload($transfer));
    }

    public function store(StoreSafeTransferRequest $request, SafeTransferService $service): JsonResponse
    {
        $transfer = $service->create($request->validated(), $request->user()->id);
        app(ActivityLogger::class)->log('safe_transfer.created', $transfer, [
            'ref' => $transfer->ref,
            'amount' => (float) $transfer->amount,
            'from' => $transfer->fromSafe?->name,
            'to' => $transfer->toSafe?->name,
        ], $request->user()->id);

        return response()->json($this->transferPayload($transfer), 201);
    }

    public function show(SafeTransfer $safeTransfer): JsonResponse
    {
        Gate::authorize('view', $safeTransfer);
        $safeTransfer->load(['fromSafe', 'toSafe', 'creator']);

        return response()->json($this->transferPayload($safeTransfer));
    }

    private function transferPayload(SafeTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'ref' => $transfer->ref,
            'from_safe_id' => $transfer->from_safe_id,
            'to_safe_id' => $transfer->to_safe_id,
            'from_safe' => $transfer->fromSafe?->name,
            'to_safe' => $transfer->toSafe?->name,
            'from_type' => $transfer->fromSafe?->type,
            'to_type' => $transfer->toSafe?->type,
            'amount' => (float) $transfer->amount,
            'currency' => $transfer->currency,
            'transfer_date' => $transfer->transfer_date?->toDateString(),
            'date' => $transfer->transfer_date?->toDateString(),
            'notes' => $transfer->notes,
            'created_by' => $transfer->created_by,
            'creator' => $transfer->creator?->name,
            'created_at' => $transfer->created_at?->toIso8601String(),
        ];
    }
}
