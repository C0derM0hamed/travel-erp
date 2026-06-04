<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Models\JournalEntry;
use App\Models\Vendor;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VendorController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Vendor::class);

        $q = strtolower((string) $request->query('search', ''));
        $query = Vendor::query()
            ->when($q, fn ($query) => $query->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%"))
            ->orderBy('id');

        return $this->paginatedResponse($request, $query, fn (Vendor $vendor) => $this->vendorPayload($vendor));
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        Gate::authorize('create', Vendor::class);

        $vendor = Vendor::create($request->validated() + ['category' => $request->input('category', 'other')]);
        app(ActivityLogger::class)->log('vendor.created', $vendor, ['name' => $vendor->name], $request->user()->id);

        return response()->json($this->vendorPayload($vendor), 201);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $vendor->update($request->validated());
        app(ActivityLogger::class)->log('vendor.updated', $vendor, ['name' => $vendor->name], $request->user()->id);

        return response()->json($this->vendorPayload($vendor->fresh()));
    }

    public function statement(Vendor $vendor): JsonResponse
    {
        Gate::authorize('view', $vendor);

        $rows = JournalEntry::with('account')
            ->whereHas('account', fn ($query) => $query->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->where('party_id', $vendor->id)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'vendor' => $this->vendorPayload($vendor),
            'balance' => $this->accounting->vendorBalance($vendor->id),
            'paid' => $this->accounting->vendorPaymentsTotal($vendor->id),
            'rows' => $rows->map(fn (JournalEntry $journal) => $this->journalPayload($journal)),
        ]);
    }
}
