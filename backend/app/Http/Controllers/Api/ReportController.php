<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReportController extends ApiController
{
    public function show(Request $request, string $type): JsonResponse
    {
        Gate::authorize('viewReports');

        return response()->json(match ($type) {
            'operations' => $this->operations($request),
            'profit' => $this->profit($request),
            'aging' => $this->aging($request),
            'employee' => $this->employee($request),
            'cashflow' => $this->cashflow($request),
            'clients-debt' => $this->clientsDebt($request),
            'vendors-balance' => $this->vendorsBalance($request),
            default => abort(404),
        });
    }

    private function applyOperationDateRange(Builder $query, Request $request): Builder
    {
        if ($request->filled('from')) {
            $query->whereDate('op_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('op_date', '<=', $request->to);
        }

        return $query;
    }

    private function operations(Request $request): array
    {
        $query = Operation::visible()->with(['client', 'service', 'vendor'])
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id');

        $this->applyOperationDateRange($query, $request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q
                ->where('ref', 'like', "%$search%")
                ->orWhere('notes', 'like', "%$search%")
                ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%$search%"))
                ->orWhereHas('service', fn ($service) => $service->where('name', 'like', "%$search%"))
                ->orWhereHas('vendor', fn ($vendor) => $vendor->where('name', 'like', "%$search%")));
        }

        $totalsQuery = clone $query;
        $totalsData = $totalsQuery->get();

        $paginated = $this->paginate($request, $query);

        return [
            'totals' => [
                'revenue' => (float) $totalsData->sum('client_price'),
                'cost' => (float) $totalsData->sum('vendor_cost'),
                'profit' => (float) $totalsData->sum('profit'),
            ],
            'rows' => $paginated->getCollection()->map(fn (Operation $operation) => $this->operationPayload($operation))->values(),
            'meta' => $this->paginationMeta($paginated),
        ];
    }

    private function profit(Request $request): array
    {
        return ['rows' => Service::orderBy('id')->get()->map(function (Service $service) use ($request) {
            $opsQuery = Operation::visible()->where('service_id', $service->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);
            $ops = $opsQuery->get();

            return [
                'name' => $service->name,
                'icon' => $service->icon,
                'count' => $ops->count(),
                'revenue' => (float) $ops->sum('client_price'),
                'cost' => (float) $ops->sum('vendor_cost'),
                'profit' => (float) $ops->sum('profit'),
            ];
        })->sortByDesc('profit')->values()];
    }

    private function aging(Request $request): array
    {
        $today = now();
        $rows = Client::visible()->get()->map(function (Client $client) use ($today, $request) {
            $row = ['name' => $client->name, 'balance' => 0.0, 'days' => 0, 'b1' => 0.0, 'b2' => 0.0, 'b3' => 0.0, 'b4' => 0.0];

            $opsQuery = Operation::visible()->where('client_id', $client->id)->where('status', '!=', 'cancelled')->orderBy('op_date');
            $this->applyOperationDateRange($opsQuery, $request);

            $opsQuery->get()->each(function (Operation $operation) use (&$row, $today) {
                $outstanding = $this->accounting->operationClientOutstanding($operation->id);
                if ($outstanding <= 0.001) {
                    return;
                }

                $days = max(0, $today->diffInDays(Carbon::parse($operation->op_date), false) * -1);
                $row['balance'] += $outstanding;
                $row['days'] = max($row['days'], $days);
                if ($days <= 30) {
                    $row['b1'] += $outstanding;
                } elseif ($days <= 60) {
                    $row['b2'] += $outstanding;
                } elseif ($days <= 90) {
                    $row['b3'] += $outstanding;
                } else {
                    $row['b4'] += $outstanding;
                }
            });

            return $row['balance'] > 0.001 ? $row : null;
        })->filter()->sortByDesc('balance')->values();

        return ['rows' => $rows];
    }

    private function employee(Request $request): array
    {
        $usersQuery = User::query()->orderBy('name');
        $officeId = app(\App\Support\OfficeContext::class)->id();
        if ($officeId) {
            $usersQuery->where('office_id', $officeId);
        }

        return ['rows' => $usersQuery->get()->map(function (User $user) use ($request) {
            $opsQuery = Operation::visible()->where('created_by', $user->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);
            $ops = $opsQuery->get();

            return [
                'name' => $user->name,
                'role' => $user->role_label,
                'count' => $ops->count(),
                'revenue' => (float) $ops->sum('client_price'),
                'profit' => (float) $ops->sum('profit'),
            ];
        })->sortByDesc('revenue')->values()];
    }

    private function cashflow(Request $request): array
    {
        $safes = Safe::orderBy('id')->get();
        $running = $safes->mapWithKeys(fn (Safe $safe) => [$safe->id => (float) $safe->opening_balance])->all();

        $datesQuery = JournalEntry::whereHas('account', fn ($query) => $query->whereNotNull('safe_id'));
        if ($request->filled('from')) {
            $datesQuery->whereDate('entry_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $datesQuery->whereDate('entry_date', '<=', $request->to);
        }

        $dates = $datesQuery->pluck('entry_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->sort()
            ->values();

        return [
            'safes' => $safes->map(fn (Safe $safe) => ['id' => $safe->id, 'name' => $safe->name, 'type' => $safe->type])->values(),
            'rows' => $dates->map(function ($date) use (&$running, $safes) {
                $safeEntries = JournalEntry::with('account')->whereDate('entry_date', $date)->whereHas('account', fn ($query) => $query->whereNotNull('safe_id'))->get();
                $in = (float) $safeEntries->sum('debit');
                $out = (float) $safeEntries->sum('credit');
                foreach ($safeEntries as $journal) {
                    $safeId = (int) $journal->account->safe_id;
                    $running[$safeId] = ($running[$safeId] ?? 0) + (float) $journal->debit - (float) $journal->credit;
                }
                $safeBalances = $safes->mapWithKeys(fn (Safe $safe) => [$safe->id => round((float) ($running[$safe->id] ?? 0), 3)]);

                $cashTotal = $safes->where('type', 'cash')->sum(fn (Safe $safe) => (float) ($running[$safe->id] ?? 0));
                $bankTotal = $safes->where('type', 'bank')->sum(fn (Safe $safe) => (float) ($running[$safe->id] ?? 0));

                return [
                    'date' => $date,
                    'inflow' => $in,
                    'outflow' => $out,
                    'net' => $in - $out,
                    'safes' => $safeBalances,
                    'cash' => round($cashTotal, 3),
                    'bank' => round($bankTotal, 3),
                ];
            }),
        ];
    }

    private function clientsDebt(Request $request): array
    {
        $rows = Client::visible()->get()->map(function (Client $client) use ($request) {
            $balance = $this->accounting->clientBalance($client->id);
            $opsQuery = Operation::visible()->where('client_id', $client->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);

            if ($request->filled('from') || $request->filled('to')) {
                if ((clone $opsQuery)->count() === 0) {
                    return null;
                }
            }

            return [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'nationality' => $client->nationality,
                'totalPurchases' => (float) (clone $opsQuery)->sum('client_price'),
                'totalPaid' => $this->accounting->clientReceiptsTotal($client->id),
                'balance' => $balance,
                'opsCount' => (clone $opsQuery)->count(),
                'lastOpDate' => (clone $opsQuery)->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->filter()->where('balance', '>', 0)->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalDebt' => (float) $rows->sum('balance')];
    }

    private function vendorsBalance(Request $request): array
    {
        $rows = Vendor::all()->map(function (Vendor $vendor) use ($request) {
            $balance = $this->accounting->vendorBalance($vendor->id);
            $opsQuery = Operation::where('vendor_id', $vendor->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);

            if ($request->filled('from') || $request->filled('to')) {
                if ((clone $opsQuery)->count() === 0) {
                    return null;
                }
            }

            return [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'category' => $vendor->category,
                'phone' => $vendor->phone,
                'contact' => $vendor->contact,
                'totalServices' => (float) (clone $opsQuery)->sum('vendor_cost'),
                'totalPaid' => $this->accounting->vendorPaymentsTotal($vendor->id),
                'balance' => $balance,
                'opsCount' => (clone $opsQuery)->count(),
                'lastOpDate' => (clone $opsQuery)->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->filter()->where('balance', '>', 0)->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalOwed' => (float) $rows->sum('balance')];
    }
}
