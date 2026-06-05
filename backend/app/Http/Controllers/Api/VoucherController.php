<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreVoucherRequest;
use App\Models\Voucher;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VoucherController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Voucher::class);

        $query = Voucher::with(['safe', 'operation'])->orderByDesc('id');
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('from')) {
            $query->whereDate('voucher_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('voucher_date', '<=', $request->to);
        }

        return $this->paginatedResponse($request, $query, fn (Voucher $voucher) => $this->voucherPayload($voucher));
    }

    public function store(StoreVoucherRequest $request, VoucherService $service): JsonResponse
    {
        Gate::authorize('create', Voucher::class);

        $voucher = $service->create($request->validated(), $request->user()->id);

        return response()->json($this->voucherPayload($voucher), 201);
    }

    public function show(Voucher $voucher): JsonResponse
    {
        Gate::authorize('view', $voucher);

        return response()->json($this->voucherPayload($voucher->load(['safe', 'operation'])));
    }

    public function void(Voucher $voucher, VoucherService $service, Request $request): JsonResponse
    {
        Gate::authorize('void', $voucher);

        $voucher = $service->void($voucher, $request->user()->id);

        return response()->json($this->voucherPayload($voucher));
    }
}
