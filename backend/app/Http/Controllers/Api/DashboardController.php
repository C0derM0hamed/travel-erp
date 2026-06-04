<?php

namespace App\Http\Controllers\Api;

use App\Models\Client;
use App\Models\Operation;
use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;

class DashboardController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        $today = now()->toDateString();
        $todayOps = Operation::whereDate('op_date', $today)->where('status', '!=', 'cancelled')->get();
        $days = collect(range(6, 0))->map(fn ($i) => now()->subDays($i)->toDateString());
        $receipts = $days->map(fn ($day) => $this->accounting->clientReceiptsOnDate($day));
        $payments = $days->map(fn ($day) => $this->accounting->vendorPaymentsOnDate($day));
        $debtors = Client::query()->select(['id', 'name'])->get()
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->name, 'balance' => $this->accounting->clientBalance($client->id)])
            ->where('balance', '>', 0)->sortByDesc('balance')->values()->take(5);
        $creditors = Vendor::query()->select(['id', 'name'])->get()
            ->map(fn (Vendor $vendor) => ['id' => $vendor->id, 'name' => $vendor->name, 'balance' => $this->accounting->vendorBalance($vendor->id)])
            ->where('balance', '>', 0)->sortByDesc('balance')->values()->take(5);
        $overdueCutoff = now()->subDays(7)->toDateString();
        $overdue = Operation::with(['client', 'service', 'vendor'])
            ->where('status', '!=', 'cancelled')
            ->where('status', '!=', 'completed')
            ->whereDate('op_date', '<=', $overdueCutoff)
            ->orderBy('op_date')
            ->get()
            ->filter(fn (Operation $operation) => $this->accounting->operationClientOutstanding($operation->id) > 0.001)
            ->take(5)
            ->values();

        return response()->json([
            'today_sales' => (float) $todayOps->sum('client_price'),
            'today_profit' => (float) $todayOps->sum('profit'),
            'total_receipts' => $this->accounting->totalClientReceipts(),
            'total_cash_receipts' => $this->accounting->totalCashReceipts(),
            'total_payments' => $this->accounting->totalVendorPayments(),
            'week' => ['days' => $days->map(fn ($day) => substr($day, 5))->values(), 'receipts' => $receipts, 'payments' => $payments],
            'services' => Service::orderBy('id')->get()->map(fn (Service $service) => ['name' => $service->name, 'count' => Operation::where('service_id', $service->id)->where('status', '!=', 'cancelled')->count()]),
            'last_operations' => Operation::with(['client', 'service', 'vendor'])->latest('id')->take(10)->get()->map(fn (Operation $operation) => $this->operationPayload($operation)),
            'overdue_operations' => $overdue->map(fn (Operation $operation) => $this->operationPayload($operation)),
            'top_debtors' => $debtors,
            'top_creditors' => $creditors,
        ]);
    }
}
