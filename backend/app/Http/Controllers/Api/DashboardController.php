<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to', now()->toDateString());

        if ($from) {
            return response()->json($this->rangePayload($from, $to));
        }

        return response()->json($this->defaultPayload());
    }

    private function defaultPayload(): array
    {
        $today = now()->toDateString();
        $todayOps = Operation::visible()->whereDate('op_date', $today)->where('status', '!=', 'cancelled')->get();
        $days = collect(range(6, 0))->map(fn ($i) => now()->subDays($i)->toDateString());
        return $this->buildPayload(
            salesLabel: 'مبيعات اليوم',
            profitLabel: 'ربح متوقع اليوم',
            sales: (float) $todayOps->sum('client_price'),
            profit: (float) $todayOps->sum('profit'),
            salesSub: 'اليوم: '.$todayOps->count().' عمليات',
            days: $days,
            rangeOps: null,
            opsForTotals: $todayOps,
        );
    }

    private function rangePayload(string $from, string $to): array
    {
        $rangeOps = Operation::visible()->with(['client', 'service', 'vendor'])
            ->where('status', '!=', 'cancelled')
            ->whereDate('op_date', '>=', $from)
            ->whereDate('op_date', '<=', $to)
            ->get();

        $start = \Carbon\Carbon::parse($from);
        $end = \Carbon\Carbon::parse($to);
        $days = collect();
        for ($date = $start->copy(); $date->lte($end) && $days->count() < 31; $date->addDay()) {
            $days->push($date->toDateString());
        }
        return $this->buildPayload(
            salesLabel: 'مبيعات الفترة',
            profitLabel: 'ربح الفترة',
            sales: (float) $rangeOps->sum('client_price'),
            profit: (float) $rangeOps->sum('profit'),
            salesSub: $rangeOps->count().' عملية',
            days: $days,
            rangeOps: $rangeOps,
            from: $from,
            to: $to,
            opsForTotals: $rangeOps,
        );
    }

    private function buildPayload(
        string $salesLabel,
        string $profitLabel,
        float $sales,
        float $profit,
        string $salesSub,
        $days,
        $rangeOps,
        ?string $from = null,
        ?string $to = null,
        $opsForTotals = null,
    ): array {
        $currencies = app(CurrencyService::class);
        $totalsOps = $opsForTotals ?? $rangeOps ?? collect();
        $salesByCurrency = $currencies->groupedSums($totalsOps, ['client_price']);
        $profitByCurrency = $currencies->groupedSums($totalsOps, ['profit']);

        $voucherQuery = Voucher::query()->whereNull('voided_at');
        if ($from && $to) {
            $voucherQuery->whereDate('voucher_date', '>=', $from)->whereDate('voucher_date', '<=', $to);
        } elseif (! $from) {
            $voucherQuery->whereDate('voucher_date', now()->toDateString());
        }
        $receiptsByCurrency = $currencies->groupedSums(
            (clone $voucherQuery)->where('type', 'receipt')->get(),
            ['amount'],
        );
        $paymentsByCurrency = $currencies->groupedSums(
            (clone $voucherQuery)->where('type', 'payment')->get(),
            ['amount'],
        );
        $debtors = Client::query()->visible()->select(['id', 'name'])->get()
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'balance' => $this->accounting->clientBalance($client->id),
                'balance_by_currency' => $this->accounting->clientBalanceByCurrency($client->id),
            ])
            ->filter(fn (array $row) => collect($row['balance_by_currency'])->contains(fn (array $group) => ($group['balance'] ?? 0) > 0.001)
                || ($row['balance'] ?? 0) > 0.001)
            ->sortByDesc(fn (array $row) => collect($row['balance_by_currency'])->max('balance') ?: ($row['balance'] ?? 0))
            ->values()->take(5);
        $creditors = Vendor::query()->select(['id', 'name'])->get()
            ->map(fn (Vendor $vendor) => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'balance' => $this->accounting->vendorBalance($vendor->id),
                'balance_by_currency' => $this->accounting->vendorBalanceByCurrency($vendor->id),
            ])
            ->filter(fn (array $row) => collect($row['balance_by_currency'])->contains(fn (array $group) => ($group['balance'] ?? 0) > 0.001)
                || ($row['balance'] ?? 0) > 0.001)
            ->sortByDesc(fn (array $row) => collect($row['balance_by_currency'])->max('balance') ?: ($row['balance'] ?? 0))
            ->values()->take(5);

        $overdueCutoff = now()->subDays(7)->toDateString();
        $overdueQuery = Operation::visible()->with(['client', 'service', 'vendor'])
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'completed')
            ->whereDate('op_date', '<=', $overdueCutoff)
            ->orderBy('op_date');

        if ($from && $to) {
            $overdueQuery->whereDate('op_date', '>=', $from)->whereDate('op_date', '<=', $to);
        }

        $overdue = $overdueQuery->get()
            ->filter(fn (Operation $operation) => $this->accounting->operationClientOutstanding($operation->id) > 0.001)
            ->take(5)
            ->values();

        $lastOpsQuery = Operation::visible()->with(['client', 'service', 'vendor'])->latest('id');
        if ($rangeOps !== null) {
            $lastOps = $rangeOps->sortByDesc('id')->take(10)->values();
            $services = Service::orderBy('id')->get()->map(function (Service $service) use ($from, $to) {
                $count = Operation::visible()->where('service_id', $service->id)
                    ->where('status', '!=', 'cancelled')
                    ->whereDate('op_date', '>=', $from)
                    ->whereDate('op_date', '<=', $to)
                    ->count();

                return ['name' => $service->name, 'count' => $count];
            });
        } else {
            $lastOps = $lastOpsQuery->take(10)->get();
            $services = Service::orderBy('id')->get()->map(fn (Service $service) => [
                'name' => $service->name,
                'count' => Operation::visible()->where('service_id', $service->id)->where('status', '!=', 'cancelled')->count(),
            ]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'sales_label' => $salesLabel,
            'profit_label' => $profitLabel,
            'today_sales' => $sales,
            'today_profit' => $profit,
            'sales_sub' => $salesSub,
            'total_receipts' => $this->accounting->totalClientReceipts(),
            'total_cash_receipts' => $this->accounting->totalCashReceipts(),
            'total_payments' => $this->accounting->totalVendorPayments(),
            'week' => [
                'days' => $days->map(fn ($day) => substr((string) $day, 5))->values(),
                'receipts_by_currency' => $this->weekVoucherAmountsByCurrency($days, 'receipt', $from, $to),
                'payments_by_currency' => $this->weekVoucherAmountsByCurrency($days, 'payment', $from, $to),
            ],
            'services' => $services,
            'last_operations' => $lastOps->map(fn (Operation $operation) => $this->operationPayload($operation)),
            'overdue_operations' => $overdue->map(fn (Operation $operation) => $this->operationPayload($operation)),
            'top_debtors' => $debtors,
            'top_creditors' => $creditors,
            'sales_by_currency' => collect($salesByCurrency)->map(fn (array $row) => [
                'code' => $row['code'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'amount' => (float) ($row['client_price'] ?? 0),
            ])->values(),
            'profit_by_currency' => collect($profitByCurrency)->map(fn (array $row) => [
                'code' => $row['code'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'amount' => (float) ($row['profit'] ?? 0),
            ])->values(),
            'receipts_by_currency' => collect($receiptsByCurrency)->map(fn (array $row) => [
                'code' => $row['code'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'amount' => (float) ($row['amount'] ?? 0),
            ])->values(),
            'payments_by_currency' => collect($paymentsByCurrency)->map(fn (array $row) => [
                'code' => $row['code'],
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'amount' => (float) ($row['amount'] ?? 0),
            ])->values(),
        ];
    }

    /** @return array<int, array{code:string,symbol:string,name:string,amounts:array<int,float>}> */
    private function weekVoucherAmountsByCurrency($days, string $type, ?string $from, ?string $to): array
    {
        $currencies = app(CurrencyService::class);
        $dayStrings = collect($days)->map(fn ($day) => (string) $day)->values();

        $query = Voucher::query()->whereNull('voided_at')->where('type', $type);
        if ($dayStrings->isNotEmpty()) {
            $query->whereDate('voucher_date', '>=', $dayStrings->first())
                ->whereDate('voucher_date', '<=', $dayStrings->last());
        }
        if ($from && $to) {
            $query->whereDate('voucher_date', '>=', $from)->whereDate('voucher_date', '<=', $to);
        } elseif (! $from) {
            $query->whereDate('voucher_date', now()->toDateString());
        }

        $vouchers = $query->get();

        return $vouchers->groupBy(fn (Voucher $voucher) => strtoupper((string) ($voucher->currency ?: $currencies->officeCurrency(officeId: $voucher->office_id)->code)))
            ->map(function ($group, string $code) use ($dayStrings, $currencies) {
                $currency = $currencies->byCode($code) ?? $currencies->defaultCurrency();
                $payload = $currencies->payload($currency);

                return [
                    'code' => $payload['code'],
                    'symbol' => $payload['symbol'],
                    'name' => $payload['name'],
                    'amounts' => $dayStrings->map(function (string $day) use ($group) {
                        return (float) $group
                            ->filter(fn (Voucher $voucher) => $voucher->voucher_date?->toDateString() === $day)
                            ->sum('amount');
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
