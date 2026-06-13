<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\AccountingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ExportReportService
{
    public function __construct(
        private ExportQueryService $queries,
        private AccountingService $accounting,
    ) {}

    public function data(string $type, Request $request): array
    {
        return match ($type) {
            'operations' => $this->operations($request),
            'profit' => $this->profit($request),
            'aging' => $this->aging($request),
            'employee' => $this->employee($request),
            'cashflow' => $this->cashflow($request),
            'clients-debt' => $this->clientsDebt($request),
            'vendors-balance' => $this->vendorsBalance($request),
            default => abort(404),
        };
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
        $query = $this->queries->operationsQuery($request)->where('status', '!=', 'cancelled');
        $totalsData = (clone $query)->get();

        return [
            'title' => 'تقرير العمليات',
            'totals' => [
                'revenue' => (float) $totalsData->sum('client_price'),
                'cost' => (float) $totalsData->sum('vendor_cost'),
                'profit' => (float) $totalsData->sum('profit'),
            ],
            'headers' => ['المرجع', 'التاريخ', 'العميل', 'الخدمة', 'المورد', 'الإيراد', 'التكلفة', 'الربح', 'الحالة'],
            'rows' => $this->collectOperationRows($query),
        ];
    }

    /** @return list<list<string|float>> */
    private function collectOperationRows(Builder $query): array
    {
        $rows = [];
        foreach ($query->clone()->lazy(500) as $operation) {
            $rows[] = $this->operationRow($operation);
        }

        return $rows;
    }

    /** @return list<string|float> */
    private function operationRow(Operation $operation): array
    {
        return [
            $operation->ref,
            $operation->op_date?->toDateString() ?? '',
            $operation->client?->name ?? '',
            $operation->service?->name ?? '',
            $operation->vendor?->name ?? '',
            (float) $operation->client_price,
            (float) $operation->vendor_cost,
            (float) $operation->profit,
            ExportLabels::operationStatus($operation->status),
        ];
    }

    private function profit(Request $request): array
    {
        $rows = Service::orderBy('id')->get()->map(function (Service $service) use ($request) {
            $opsQuery = Operation::visible()->where('service_id', $service->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);
            $ops = $opsQuery->get();
            $revenue = (float) $ops->sum('client_price');

            return [
                $service->name,
                $ops->count(),
                $revenue,
                (float) $ops->sum('vendor_cost'),
                (float) $ops->sum('profit'),
                $revenue > 0 ? round(((float) $ops->sum('profit') / $revenue) * 100, 1).'%' : '—',
            ];
        })->sortByDesc(fn ($row) => $row[4])->values()->all();

        return [
            'title' => 'تقرير الربحية',
            'headers' => ['الخدمة', 'عدد العمليات', 'الإيرادات', 'التكاليف', 'الربح', 'هامش الربح'],
            'rows' => $rows,
        ];
    }

    private function aging(Request $request): array
    {
        $today = now();
        $rows = Client::query()->visible()->orderBy('name')->get()->map(function (Client $client) use ($today, $request) {
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

            return $row['balance'] > 0.001 ? [
                $row['name'],
                round($row['balance'], 3),
                $row['days'],
                $row['b1'] > 0 ? round($row['b1'], 3) : '—',
                $row['b2'] > 0 ? round($row['b2'], 3) : '—',
                $row['b3'] > 0 ? round($row['b3'], 3) : '—',
                $row['b4'] > 0 ? round($row['b4'], 3) : '—',
            ] : null;
        })->filter()->sortByDesc(fn ($row) => $row[1])->values()->all();

        return [
            'title' => 'تقرير التقادم',
            'headers' => ['العميل', 'الرصيد', 'عمر الدين', '1-30 يوم', '31-60 يوم', '61-90 يوم', '+90 يوم'],
            'rows' => $rows,
        ];
    }

    private function employee(Request $request): array
    {
        $rows = $this->queries->scopedUsersQuery()->get()->map(function (User $user) use ($request) {
            $opsQuery = Operation::visible()->where('created_by', $user->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);
            $ops = $opsQuery->get();

            return [
                $user->name,
                $user->role_label ?: $user->role,
                $ops->count(),
                (float) $ops->sum('client_price'),
                (float) $ops->sum('profit'),
            ];
        })->sortByDesc(fn ($row) => $row[3])->values()->all();

        return [
            'title' => 'أداء الموظفين',
            'headers' => ['الموظف', 'الدور', 'العمليات', 'الإيراد', 'الربح'],
            'rows' => $rows,
        ];
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

        $headers = array_merge(['التاريخ', 'وارد', 'صادر', 'صافي'], $safes->map(fn (Safe $safe) => 'رصيد '.$safe->name)->all());
        $rows = $dates->map(function ($date) use (&$running, $safes) {
            $safeEntries = JournalEntry::with('account')->whereDate('entry_date', $date)->whereHas('account', fn ($query) => $query->whereNotNull('safe_id'))->get();
            $in = (float) $safeEntries->sum('debit');
            $out = (float) $safeEntries->sum('credit');
            foreach ($safeEntries as $journal) {
                $safeId = (int) $journal->account->safe_id;
                $running[$safeId] = ($running[$safeId] ?? 0) + (float) $journal->debit - (float) $journal->credit;
            }

            return array_merge(
                [$date, $in, $out, $in - $out],
                $safes->map(fn (Safe $safe) => round((float) ($running[$safe->id] ?? 0), 3))->all(),
            );
        })->all();

        return [
            'title' => 'التدفق النقدي',
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    private function clientsDebt(Request $request): array
    {
        $rows = Client::query()->visible()->orderBy('name')->get()->map(function (Client $client) use ($request) {
            $balance = $this->accounting->clientBalance($client->id);
            $opsQuery = Operation::visible()->where('client_id', $client->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);

            if ($request->filled('from') || $request->filled('to')) {
                if ((clone $opsQuery)->count() === 0) {
                    return null;
                }
            }

            if ($balance <= 0.001) {
                return null;
            }

            return [
                $client->name,
                $client->phone ?? '',
                (float) (clone $opsQuery)->sum('client_price'),
                $this->accounting->clientReceiptsTotal($client->id),
                $balance,
                (clone $opsQuery)->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->filter()->sortByDesc(fn ($row) => $row[4])->values()->all();

        return [
            'title' => 'مديونية العملاء',
            'headers' => ['العميل', 'الهاتف', 'المشتريات', 'المدفوع', 'الرصيد', 'آخر عملية'],
            'rows' => $rows,
        ];
    }

    private function vendorsBalance(Request $request): array
    {
        $rows = Vendor::query()->orderBy('name')->get()->map(function (Vendor $vendor) use ($request) {
            $balance = $this->accounting->vendorBalance($vendor->id);
            $opsQuery = Operation::visible()->where('vendor_id', $vendor->id)->where('status', '!=', 'cancelled');
            $this->applyOperationDateRange($opsQuery, $request);

            if ($request->filled('from') || $request->filled('to')) {
                if ((clone $opsQuery)->count() === 0) {
                    return null;
                }
            }

            if ($balance <= 0.001) {
                return null;
            }

            return [
                $vendor->name,
                $vendor->category ?? '',
                (float) (clone $opsQuery)->sum('vendor_cost'),
                $this->accounting->vendorPaymentsTotal($vendor->id),
                $balance,
                (clone $opsQuery)->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->filter()->sortByDesc(fn ($row) => $row[4])->values()->all();

        return [
            'title' => 'أرصدة الموردين',
            'headers' => ['المورد', 'التصنيف', 'إجمالي الخدمات', 'المدفوع', 'الرصيد', 'آخر عملية'],
            'rows' => $rows,
        ];
    }
}
