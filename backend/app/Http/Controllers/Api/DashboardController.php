<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\Vendor;
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
        $receipts = $days->map(fn ($day) => $this->accounting->clientReceiptsOnDate($day));
        $payments = $days->map(fn ($day) => $this->accounting->vendorPaymentsOnDate($day));

        return $this->buildPayload(
            salesLabel: 'مبيعات اليوم',
            profitLabel: 'ربح متوقع اليوم',
            sales: (float) $todayOps->sum('client_price'),
            profit: (float) $todayOps->sum('profit'),
            salesSub: 'اليوم: '.$todayOps->count().' عمليات',
            days: $days,
            receipts: $receipts,
            payments: $payments,
            rangeOps: null,
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
        $receipts = $days->map(fn ($day) => $this->accounting->clientReceiptsOnDate($day));
        $payments = $days->map(fn ($day) => $this->accounting->vendorPaymentsOnDate($day));

        return $this->buildPayload(
            salesLabel: 'مبيعات الفترة',
            profitLabel: 'ربح الفترة',
            sales: (float) $rangeOps->sum('client_price'),
            profit: (float) $rangeOps->sum('profit'),
            salesSub: $rangeOps->count().' عملية',
            days: $days,
            receipts: $receipts,
            payments: $payments,
            rangeOps: $rangeOps,
            from: $from,
            to: $to,
        );
    }

    private function buildPayload(
        string $salesLabel,
        string $profitLabel,
        float $sales,
        float $profit,
        string $salesSub,
        $days,
        $receipts,
        $payments,
        $rangeOps,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $debtors = Client::query()->visible()->select(['id', 'name'])->get()
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name, 'balance' => $this->accounting->clientBalance($client->id)])
            ->where('balance', '>', 0)->sortByDesc('balance')->values()->take(5);
        $creditors = Vendor::query()->select(['id', 'name'])->get()
            ->map(fn (Vendor $vendor) => ['id' => $vendor->id, 'name' => $vendor->name, 'balance' => $this->accounting->vendorBalance($vendor->id)])
            ->where('balance', '>', 0)->sortByDesc('balance')->values()->take(5);

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
            'week' => ['days' => $days->map(fn ($day) => substr($day, 5))->values(), 'receipts' => $receipts, 'payments' => $payments],
            'services' => $services,
            'last_operations' => $lastOps->map(fn (Operation $operation) => $this->operationPayload($operation)),
            'overdue_operations' => $overdue->map(fn (Operation $operation) => $this->operationPayload($operation)),
            'top_debtors' => $debtors,
            'top_creditors' => $creditors,
        ];
    }
}
