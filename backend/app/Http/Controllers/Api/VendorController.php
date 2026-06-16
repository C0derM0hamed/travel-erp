<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VendorController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Vendor::class);

        $q = strtolower((string) $request->query('search', ''));
        $query = Vendor::query()
            ->when($q, fn ($query) => $query->where('name', 'like', "%$q%")
                ->orWhere('phone', 'like', "%$q%")
                ->orWhere('contact', 'like', "%$q%")
                ->orWhere('category', 'like', "%$q%"))
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

    public function destroy(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('delete', $vendor);
        $this->ensureVendorDeletable($vendor);

        $name = $vendor->name;
        $vendorId = $vendor->id;
        $vendor->delete();
        app(ActivityLogger::class)->log('vendor.deleted', null, ['id' => $vendorId, 'name' => $name], $request->user()->id);

        return response()->json(['message' => 'تم حذف المكتب بنجاح']);
    }

    public function statement(Request $request, Vendor $vendor): JsonResponse
    {
        Gate::authorize('view', $vendor);

        $rowsQuery = JournalEntry::with('account')
            ->whereHas('account', fn ($query) => $query->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->where('party_id', $vendor->id)
            ->orderBy('entry_date')
            ->orderBy('id');

        if ($request->filled('from')) {
            $rowsQuery->whereDate('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $rowsQuery->whereDate('entry_date', '<=', $request->to);
        }

        $rows = $rowsQuery->get();
        $summaryByCurrency = $this->accounting->vendorStatementSummary(
            $vendor->id,
            $vendor->office_id,
            $request->input('from'),
            $request->input('to'),
        );

        return response()->json([
            'vendor' => $this->vendorPayload($vendor),
            'balance' => (float) collect($summaryByCurrency)->sum('balance'),
            'paid' => (float) collect($summaryByCurrency)->sum('paid'),
            'credits' => (float) collect($summaryByCurrency)->sum('credits'),
            'summary_by_currency' => $summaryByCurrency,
            'rows' => $rows->map(fn (JournalEntry $journal) => $this->journalPayload($journal)),
        ]);
    }

    private function ensureVendorDeletable(Vendor $vendor): void
    {
        if (Operation::where('vendor_id', $vendor->id)->exists()) {
            throw ValidationException::withMessages([
                'vendor' => 'لا يمكن حذف المكتب لوجود عمليات مرتبطة به. ألغِ العمليات أولاً أو احتفظ بالسجل.',
            ]);
        }

        if (Voucher::where('party_type', 'vendor')->where('party_id', $vendor->id)->exists()) {
            throw ValidationException::withMessages([
                'vendor' => 'لا يمكن حذف المكتب لوجود سندات مالية مرتبطة به.',
            ]);
        }

        if (JournalEntry::where('party_type', 'vendor')->where('party_id', $vendor->id)->exists()) {
            throw ValidationException::withMessages([
                'vendor' => 'لا يمكن حذف المكتب لوجود قيود محاسبية مرتبطة به.',
            ]);
        }

        if (abs($this->accounting->vendorBalance($vendor->id)) > 0.001) {
            throw ValidationException::withMessages([
                'vendor' => 'لا يمكن حذف المكتب لوجود رصيد مستحق.',
            ]);
        }
    }
}
