<?php

namespace App\Http\Controllers\Api;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JournalController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewReports');

        $query = JournalEntry::with('account')->orderBy('entry_date')->orderBy('id');
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('ref', 'like', "%$search%")->orWhere('description', 'like', "%$search%"));
        }
        if ($request->filled('account') && $request->account !== 'all') {
            $query->whereHas('account', fn ($q) => $q->where('name', $request->account)->orWhere('code', $request->account));
        }
        if ($request->filled('from')) {
            $query->whereDate('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('entry_date', '<=', $request->to);
        }

        $isFiltered = $request->filled('search')
            || ($request->filled('account') && $request->account !== 'all')
            || $request->filled('from')
            || $request->filled('to');
        $totals = (clone $query)->reorder()->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')->first();
        $debit = (float) ($totals->debit ?? 0);
        $credit = (float) ($totals->credit ?? 0);
        $totalPayload = ['debit' => $debit, 'credit' => $credit, 'filtered' => $isFiltered];
        if (! $isFiltered) {
            $totalPayload['balanced'] = abs($debit - $credit) < 0.01;
        }

        $paginated = $this->paginate($request, $query);

        return response()->json([
            'data' => $paginated->getCollection()->map(fn (JournalEntry $journal) => $this->journalPayload($journal))->values(),
            'meta' => $this->paginationMeta($paginated),
            'totals' => $totalPayload,
            'accounts' => ChartOfAccount::orderBy('code')->pluck('name'),
        ]);
    }
}
