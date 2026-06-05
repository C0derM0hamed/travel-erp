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
            'profit' => $this->profit(),
            'aging' => $this->aging(),
            'employee' => $this->employee(),
            'cashflow' => $this->cashflow(),
            'clients-debt' => $this->clientsDebt(),
            'vendors-balance' => $this->vendorsBalance(),
            default => abort(404),
        });
    }

    private function operations(Request $request): array
    {
        $query = Operation::with(['client', 'service', 'vendor'])
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('op_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('op_date', '<=', $request->to);
        }

        $data = $query->get();

        return [
            'totals' => [
                'revenue' => (float) $data->sum('client_price'),
                'cost' => (float) $data->sum('vendor_cost'),
                'profit' => (float) $data->sum('profit'),
            ],
            'rows' => $data->map(fn (Operation $operation) => $this->operationPayload($operation)),
        ];
    }

    private function profit(): array
    {
        return ['rows' => Service::orderBy('id')->get()->map(function (Service $service) {
            $ops = Operation::where('service_id', $service->id)->where('status', '!=', 'cancelled')->get();

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

    private function aging(): array
    {
        $today = now();
        $rows = Client::all()->map(function (Client $client) use ($today) {
            $row = ['name' => $client->name, 'balance' => 0.0, 'days' => 0, 'b1' => 0.0, 'b2' => 0.0, 'b3' => 0.0, 'b4' => 0.0];

            Operation::where('client_id', $client->id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('op_date')
                ->get()
                ->each(function (Operation $operation) use (&$row, $today) {
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

    private function employee(): array
    {
        return ['rows' => User::all()->map(function (User $user) {
            $ops = Operation::where('created_by', $user->id)->where('status', '!=', 'cancelled')->get();

            return [
                'name' => $user->name,
                'role' => $user->role_label,
                'count' => $ops->count(),
                'revenue' => (float) $ops->sum('client_price'),
                'profit' => (float) $ops->sum('profit'),
            ];
        })->sortByDesc('revenue')->values()];
    }

    private function cashflow(): array
    {
        $safes = Safe::orderBy('id')->get();
        $running = $safes->mapWithKeys(fn (Safe $safe) => [$safe->id => (float) $safe->opening_balance])->all();
        $dates = JournalEntry::whereHas('account', fn ($query) => $query->whereNotNull('safe_id'))
            ->pluck('entry_date')
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

    private function clientsDebt(): array
    {
        $rows = Client::all()->map(function (Client $client) {
            $balance = $this->accounting->clientBalance($client->id);
            $ops = Operation::where('client_id', $client->id)->where('status', '!=', 'cancelled');

            return [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'nationality' => $client->nationality,
                'totalPurchases' => (float) $ops->sum('client_price'),
                'totalPaid' => $this->accounting->clientReceiptsTotal($client->id),
                'balance' => $balance,
                'opsCount' => $ops->count(),
                'lastOpDate' => $ops->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->where('balance', '>', 0)->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalDebt' => (float) $rows->sum('balance')];
    }

    private function vendorsBalance(): array
    {
        $rows = Vendor::all()->map(function (Vendor $vendor) {
            $balance = $this->accounting->vendorBalance($vendor->id);
            $ops = Operation::where('vendor_id', $vendor->id)->where('status', '!=', 'cancelled');

            return [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'category' => $vendor->category,
                'phone' => $vendor->phone,
                'contact' => $vendor->contact,
                'totalServices' => (float) $ops->sum('vendor_cost'),
                'totalPaid' => $this->accounting->vendorPaymentsTotal($vendor->id),
                'balance' => $balance,
                'opsCount' => $ops->count(),
                'lastOpDate' => $ops->orderByDesc('op_date')->value('op_date') ?: '—',
            ];
        })->where('balance', '>', 0)->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalOwed' => (float) $rows->sum('balance')];
    }
}
