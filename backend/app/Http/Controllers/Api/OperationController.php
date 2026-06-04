<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreOperationRequest;
use App\Http\Requests\UpdateOperationRequest;
use App\Http\Requests\UpdateOperationStatusRequest;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Services\OperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Operation::class);

        $query = Operation::with(['client', 'service', 'vendor'])->orderByDesc('id');
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('service') && $request->service !== 'all') {
            $query->where('service_id', $request->service);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('ref', 'like', "%$search%")
                ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%$search%"))
                ->orWhereHas('service', fn ($service) => $service->where('name', 'like', "%$search%")));
        }

        return $this->paginatedResponse($request, $query, fn (Operation $operation) => $this->operationPayload($operation));
    }

    public function store(StoreOperationRequest $request, OperationService $service): JsonResponse
    {
        Gate::authorize('create', Operation::class);

        $operation = $service->create($request->validated(), $request->user()->id);

        return response()->json($this->operationPayload($operation), 201);
    }

    public function show(Operation $operation): JsonResponse
    {
        Gate::authorize('view', $operation);

        $operation->load(['client', 'service', 'vendor', 'vouchers']);

        return response()->json($this->operationPayload($operation) + [
            'journal' => JournalEntry::with('account')->where('operation_id', $operation->id)->orderBy('id')->get()->map(fn (JournalEntry $journal) => $this->journalPayload($journal)),
            'vouchers' => $operation->vouchers->map(fn ($voucher) => $this->voucherPayload($voucher)),
        ]);
    }

    public function update(UpdateOperationRequest $request, Operation $operation, OperationService $service): JsonResponse
    {
        Gate::authorize('update', $operation);

        $operation = $service->update($operation, $request->validated(), $request->user()->id);

        return response()->json($this->operationPayload($operation));
    }

    public function cancel(Operation $operation, OperationService $service): JsonResponse
    {
        Gate::authorize('cancel', $operation);

        return response()->json($this->operationPayload($service->cancel($operation)));
    }

    public function updateStatus(UpdateOperationStatusRequest $request, Operation $operation, OperationService $service): JsonResponse
    {
        Gate::authorize('updateStatus', $operation);

        return response()->json($this->operationPayload($service->updateStatus($operation, $request->string('status')->toString(), $request->user())));
    }
}
