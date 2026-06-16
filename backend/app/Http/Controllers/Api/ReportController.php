<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CurrencyService;
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
        $currencies = app(CurrencyService::class);

        $paginated = $this->paginate($request, $query);

        return [
            'totals' => [
                'revenue' => (float) $totalsData->sum('client_price'),
                'cost' => (float) $totalsData->sum('vendor_cost'),
                'profit' => (float) $totalsData->sum('profit'),
            ],
            'totals_by_currency' => collect($currencies->groupedSums($totalsData, ['client_price', 'vendor_cost', 'profit']))
                ->map(fn (array $row) => [
                    'code' => $row['code'],
                    'symbol' => $row['symbol'],
                    'name' => $row['name'],
                    'revenue' => (float) ($row['client_price'] ?? 0),
                    'cost' => (float) ($row['vendor_cost'] ?? 0),
                    'profit' => (float) ($row['profit'] ?? 0),
                ])->values(),
            'rows' => $paginated->getCollection()->map(fn (Operation $operation) => $this->operationPayload($operation))->values(),
            'meta' => $this->paginationMeta($paginated),
        ];
    }

    private function profit(Request $request): array
    {
        $currencies = app(CurrencyService::class);
        $allOps = collect();

        $rows = Service::orderBy('id')->get()->map(function (Service $service) use ($request, &$allOps) {
            $opsQuery = Operation::visible()->where('service_id', $service->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);
            $ops = $opsQuery->get();
            $allOps = $allOps->merge($ops);

            return [
                'name' => $service->name,
                'icon' => $service->icon,
                'count' => $ops->count(),
                'revenue' => (float) $ops->sum('client_price'),
                'cost' => (float) $ops->sum('vendor_cost'),
                'profit' => (float) $ops->sum('profit'),
            ];
        })->sortByDesc('profit')->values();

        return [
            'rows' => $rows,
            'totals_by_currency' => collect($currencies->groupedSums($allOps, ['client_price', 'vendor_cost', 'profit']))
                ->map(fn (array $row) => [
                    'code' => $row['code'],
                    'symbol' => $row['symbol'],
                    'name' => $row['name'],
                    'revenue' => (float) ($row['client_price'] ?? 0),
                    'cost' => (float) ($row['vendor_cost'] ?? 0),
                    'profit' => (float) ($row['profit'] ?? 0),
                ])->values(),
        ];
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
            'safes' => $safes->map(fn (Safe $safe) => [
                'id' => $safe->id,
                'name' => $safe->name,
                'type' => $safe->type,
                'currency' => $safe->currency,
                'currency_symbol' => app(CurrencyService::class)->payloadForCode($safe->currency, $safe->office_id)['symbol'] ?? $safe->currency,
            ])->values(),
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
            $summaryByCurrency = $this->accounting->clientStatementSummary(
                $client->id,
                $client->office_id,
                $request->input('from'),
                $request->input('to'),
            );
            $opsQuery = Operation::visible()->where('client_id', $client->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);

            if ($request->filled('from') || $request->filled('to')) {
                if ((clone $opsQuery)->count() === 0) {
                    return null;
                }
            }

            if (! collect($summaryByCurrency)->contains(fn (array $group) => ($group['balance'] ?? 0) > 0.001)) {
                return null;
            }

            return [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'nationality' => $client->nationality,
                'summary_by_currency' => $summaryByCurrency,
                'totalPurchases' => (float) collect($summaryByCurrency)->sum('purchases'),
                'totalPaid' => (float) collect($summaryByCurrency)->sum('paid'),
                'balance' => (float) collect($summaryByCurrency)->sum('balance'),
                'opsCount' => (clone $opsQuery)->count(),
                'lastOpDate' => (clone $opsQuery)->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->filter()->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalDebt' => (float) $rows->sum('balance'), 'totals_by_currency' => $this->balanceTotalsByCurrency($rows)];
    }

    private function vendorsBalance(Request $request): array
    {
        $rows = Vendor::all()->map(function (Vendor $vendor) use ($request) {
            $summaryByCurrency = $this->accounting->vendorStatementSummary(
                $vendor->id,
                $vendor->office_id,
                $request->input('from'),
                $request->input('to'),
            );
            $opsQuery = Operation::where('vendor_id', $vendor->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);

            if ($request->filled('from') || $request->filled('to')) {
                if ((clone $opsQuery)->count() === 0) {
                    return null;
                }
            }

            if (! collect($summaryByCurrency)->contains(fn (array $group) => ($group['balance'] ?? 0) > 0.001)) {
                return null;
            }

            return [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'category' => $vendor->category,
                'phone' => $vendor->phone,
                'contact' => $vendor->contact,
                'summary_by_currency' => $summaryByCurrency,
                'totalServices' => (float) collect($summaryByCurrency)->sum('credits'),
                'totalPaid' => (float) collect($summaryByCurrency)->sum('paid'),
                'balance' => (float) collect($summaryByCurrency)->sum('balance'),
                'opsCount' => (clone $opsQuery)->count(),
                'lastOpDate' => (clone $opsQuery)->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->filter()->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalOwed' => (float) $rows->sum('balance'), 'totals_by_currency' => $this->balanceTotalsByCurrency($rows)];
    }

    /** @param \Illuminate\Support\Collection<int, array<string, mixed>> $rows */
    private function balanceTotalsByCurrency($rows): array
    {
        $currencies = app(CurrencyService::class);
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row['summary_by_currency'] ?? $row['balance_by_currency'] ?? [] as $group) {
                $code = strtoupper($group['code'] ?? '');
                if (! $code) {
                    continue;
                }
                $totals[$code] = ($totals[$code] ?? 0) + (float) ($group['balance'] ?? 0);
            }
        }

        return collect($totals)->map(function (float $amount, string $code) use ($currencies) {
            $currency = $currencies->byCode($code) ?? $currencies->defaultCurrency();
            $payload = $currencies->payload($currency);

            return [
                'code' => $payload['code'],
                'symbol' => $payload['symbol'],
                'name' => $payload['name'],
                'amount' => round($amount, 3),
            ];
        })->values()->all();
    }
}
