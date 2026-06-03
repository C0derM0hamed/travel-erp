<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\StoreOperationRequest;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\StoreVoucherRequest;
use App\Http\Requests\UpdateOperationStatusRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Services\OperationService;
use App\Services\VoucherService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TravelErpController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة']);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $users = $request->user()->role === 'admin'
            ? User::orderBy('id')->get()->map(fn ($u) => $this->userPayload($u))
            : [$this->userPayload($request->user())];

        return response()->json([
            'users' => $users,
            'services' => Service::orderBy('id')->get(),
            'vendors' => Vendor::orderBy('id')->get()->map(fn ($v) => $this->vendorPayload($v)),
            'clients' => Client::orderBy('id')->get()->map(fn ($c) => $this->clientPayload($c)),
            'operations' => Operation::with(['client', 'service', 'vendor'])->orderBy('id')->get()->map(fn ($o) => $this->operationPayload($o)),
            'vouchers' => Voucher::with(['safe', 'operation'])->orderBy('id')->get()->map(fn ($v) => $this->voucherPayload($v)),
            'safes' => Safe::with('account')->orderBy('id')->get()->map(fn ($s) => $this->safePayload($s) + [
                'balance' => $this->accounting->safeBalance($s->id),
                'movements' => $s->account
                    ? JournalEntry::with('account')->where('account_id', $s->account->id)->orderByDesc('entry_date')->orderByDesc('id')->take(10)->get()->map(fn ($j) => $this->journalPayload($j))
                    : [],
            ]),
            'metrics' => $this->metricsPayload(),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $todayOps = Operation::whereDate('op_date', $today)->where('status', '!=', 'cancelled')->get();
        $days = collect(range(6, 0))->map(fn ($i) => now()->subDays($i)->toDateString());
        $receipts = $days->map(fn ($d) => $this->accounting->clientReceiptsOnDate($d));
        $payments = $days->map(fn ($d) => $this->accounting->vendorPaymentsOnDate($d));
        $debtors = Client::all()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'balance' => $this->accounting->clientBalance($c->id)])->where('balance', '>', 0)->sortByDesc('balance')->values()->take(5);
        $creditors = Vendor::all()->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'balance' => $this->accounting->vendorBalance($v->id)])->where('balance', '>', 0)->sortByDesc('balance')->values()->take(5);
        $overdueCutoff = now()->subDays(7)->toDateString();
        $overdue = Operation::where('status', '!=', 'cancelled')
            ->where('status', '!=', 'completed')
            ->whereDate('op_date', '<=', $overdueCutoff)
            ->orderBy('op_date')
            ->get()
            ->filter(fn ($o) => $this->accounting->operationClientOutstanding($o->id) > 0.001)
            ->take(5)
            ->values();

        return response()->json([
            'today_sales' => (float) $todayOps->sum('client_price'),
            'today_profit' => (float) $todayOps->sum('profit'),
            'total_receipts' => $this->accounting->totalClientReceipts(),
            'total_cash_receipts' => $this->accounting->totalCashReceipts(),
            'total_payments' => $this->accounting->totalVendorPayments(),
            'week' => ['days' => $days->map(fn ($d) => substr($d, 5))->values(), 'receipts' => $receipts, 'payments' => $payments],
            'services' => Service::orderBy('id')->get()->map(fn ($s) => ['name' => $s->name, 'count' => Operation::where('service_id', $s->id)->where('status', '!=', 'cancelled')->count()]),
            'last_operations' => Operation::latest('id')->take(10)->get()->map(fn ($o) => $this->operationPayload($o)),
            'overdue_operations' => $overdue->map(fn ($o) => $this->operationPayload($o)),
            'top_debtors' => $debtors,
            'top_creditors' => $creditors,
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $q = strtolower((string) $request->query('search', ''));
        $query = Client::query()->when($q, fn ($query) => $query->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")->orWhere('civil_id', 'like', "%$q%"))->orderBy('id');

        return $this->paginatedResponse($request, $query, fn ($c) => $this->clientPayload($c));
    }

    public function storeClient(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return response()->json($this->clientPayload($client), 201);
    }

    public function clientStatement(Client $client): JsonResponse
    {
        $rows = JournalEntry::with('account')->whereHas('account', fn ($q) => $q->where('code', '1100'))->where('party_type', 'client')->where('party_id', $client->id)->orderBy('entry_date')->orderBy('id')->get();
        $running = 0;

        return response()->json([
            'client' => $this->clientPayload($client),
            'total_purchases' => (float) Operation::where('client_id', $client->id)->where('status', '!=', 'cancelled')->sum('client_price'),
            'paid' => $this->accounting->clientReceiptsTotal($client->id),
            'balance' => $this->accounting->clientBalance($client->id),
            'rows' => $rows->map(function ($j) use (&$running) {
                $running += (float) $j->debit - (float) $j->credit;

                return $this->journalPayload($j) + ['balance' => round($running, 3)];
            }),
        ]);
    }

    public function vendors(Request $request): JsonResponse
    {
        $q = strtolower((string) $request->query('search', ''));
        $query = Vendor::query()->when($q, fn ($query) => $query->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%"))->orderBy('id');

        return $this->paginatedResponse($request, $query, fn ($v) => $this->vendorPayload($v));
    }

    public function storeVendor(StoreVendorRequest $request): JsonResponse
    {
        $vendor = Vendor::create($request->validated() + ['category' => $request->input('category', 'other')]);

        return response()->json($this->vendorPayload($vendor), 201);
    }

    public function vendorStatement(Vendor $vendor): JsonResponse
    {
        $rows = JournalEntry::with('account')->whereHas('account', fn ($q) => $q->where('code', '2100'))->where('party_type', 'vendor')->where('party_id', $vendor->id)->orderBy('entry_date')->orderBy('id')->get();

        return response()->json([
            'vendor' => $this->vendorPayload($vendor),
            'balance' => $this->accounting->vendorBalance($vendor->id),
            'paid' => $this->accounting->vendorPaymentsTotal($vendor->id),
            'rows' => $rows->map(fn ($j) => $this->journalPayload($j)),
        ]);
    }

    public function operations(Request $request): JsonResponse
    {
        $query = Operation::with(['client', 'service', 'vendor'])->orderByDesc('id');
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('service') && $request->service !== 'all') {
            $query->where('service_id', $request->service);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('ref', 'like', "%$search%")->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$search%"))->orWhereHas('service', fn ($s) => $s->where('name', 'like', "%$search%")));
        }

        return $this->paginatedResponse($request, $query, fn ($o) => $this->operationPayload($o));
    }

    public function storeOperation(StoreOperationRequest $request, OperationService $service): JsonResponse
    {
        $operation = $service->create($request->validated(), $request->user()->id);

        return response()->json($this->operationPayload($operation), 201);
    }

    public function operationShow(Operation $operation): JsonResponse
    {
        $operation->load(['client', 'service', 'vendor', 'vouchers']);

        return response()->json($this->operationPayload($operation) + [
            'journal' => JournalEntry::with('account')->where('operation_id', $operation->id)->orderBy('id')->get()->map(fn ($j) => $this->journalPayload($j)),
            'vouchers' => $operation->vouchers->map(fn ($v) => $this->voucherPayload($v)),
        ]);
    }

    public function cancelOperation(Operation $operation, OperationService $service): JsonResponse
    {
        Gate::authorize('cancel-op');

        return response()->json($this->operationPayload($service->cancel($operation)));
    }

    public function updateOperationStatus(UpdateOperationStatusRequest $request, Operation $operation, OperationService $service): JsonResponse
    {
        Gate::authorize('update-op-status');

        return response()->json($this->operationPayload($service->updateStatus($operation, $request->string('status')->toString(), $request->user())));
    }

    public function vouchers(Request $request): JsonResponse
    {
        $query = Voucher::with(['safe', 'operation'])->orderByDesc('id');
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return $this->paginatedResponse($request, $query, fn ($v) => $this->voucherPayload($v));
    }

    public function storeVoucher(StoreVoucherRequest $request, VoucherService $service): JsonResponse
    {
        $voucher = $service->create($request->validated(), $request->user()->id);

        return response()->json($this->voucherPayload($voucher), 201);
    }

    public function voucherShow(Voucher $voucher): JsonResponse
    {
        return response()->json($this->voucherPayload($voucher->load(['safe', 'operation'])));
    }

    public function journal(Request $request): JsonResponse
    {
        $query = JournalEntry::with('account')->orderBy('entry_date')->orderBy('id');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('ref', 'like', "%$s%")->orWhere('description', 'like', "%$s%"));
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
            'data' => $paginated->getCollection()->map(fn ($j) => $this->journalPayload($j))->values(),
            'meta' => $this->paginationMeta($paginated),
            'totals' => $totalPayload,
            'accounts' => ChartOfAccount::orderBy('code')->pluck('name'),
        ]);
    }

    public function safes(): JsonResponse
    {
        return response()->json(['data' => Safe::with('account')->orderBy('id')->get()->map(fn ($s) => $this->safePayload($s) + ['balance' => $this->accounting->safeBalance($s->id), 'movements' => JournalEntry::with('account')->where('account_id', $s->account->id)->orderByDesc('entry_date')->take(10)->get()->map(fn ($j) => $this->journalPayload($j))])]);
    }

    public function reports(string $type): JsonResponse
    {
        return response()->json(match ($type) {
            'operations' => $this->reportOperations(),
            'profit' => $this->reportProfit(),
            'aging' => $this->reportAging(),
            'employee' => $this->reportEmployee(),
            'cashflow' => $this->reportCashflow(),
            'clients-debt' => $this->reportClientsDebt(),
            'vendors-balance' => $this->reportVendorsBalance(),
            default => abort(404),
        });
    }

    public function users(): JsonResponse
    {
        Gate::authorize('write-settings');

        return response()->json(['data' => User::orderBy('id')->get()->map(fn ($u) => $this->userPayload($u))]);
    }

    public function toggleService(Service $service): JsonResponse
    {
        Gate::authorize('write-settings');
        $service->update(['active' => ! $service->active]);

        return response()->json($service);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $request->user()->update($request->validated());

        return response()->json(['user' => $this->userPayload($request->user()->fresh())]);
    }

    private function paginatedResponse(Request $request, Builder $query, callable $mapper): JsonResponse
    {
        $paginated = $this->paginate($request, $query);

        return response()->json([
            'data' => $paginated->getCollection()->map($mapper)->values(),
            'meta' => $this->paginationMeta($paginated),
        ]);
    }

    private function paginate(Request $request, Builder $query): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->query('per_page', 50), 1), 500);

        return $query->paginate($perPage)->appends($request->query());
    }

    private function paginationMeta(LengthAwarePaginator $paginated): array
    {
        return [
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ];
    }

    private function metricsPayload(): array
    {
        return [
            'total_receipts' => $this->accounting->totalClientReceipts(),
            'total_cash_receipts' => $this->accounting->totalCashReceipts(),
            'total_payments' => $this->accounting->totalVendorPayments(),
            'journal_count' => JournalEntry::count(),
            'journal_balanced' => $this->accounting->isJournalBalanced(),
        ];
    }

    private function userPayload(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'roleLabel' => $u->role_label ?: $u->role, 'avatar' => $u->avatar];
    }

    private function clientPayload(Client $c): array
    {
        $data = $c->toArray();
        $data['nationality'] = $data['nationality'] ?? '';

        return $data + ['balance' => $this->accounting->clientBalance($c->id), 'operations_count' => Operation::where('client_id', $c->id)->where('status', '!=', 'cancelled')->count()];
    }

    private function vendorPayload(Vendor $v): array
    {
        return $v->toArray() + ['balance' => $this->accounting->vendorBalance($v->id)];
    }

    private function safePayload(Safe $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'type' => $s->type, 'currency' => $s->currency, 'initial' => (float) $s->opening_balance, 'opening_balance' => (float) $s->opening_balance];
    }

    private function operationPayload(Operation $o): array
    {
        return ['id' => $o->id, 'ref' => $o->ref, 'client_id' => $o->client_id, 'service_id' => $o->service_id, 'vendor_id' => $o->vendor_id, 'currency' => $o->currency, 'currency_label' => match ($o->currency) { 'KWD' => 'دينار كويتي', 'USD' => 'دولار أمريكي', 'SAR' => 'ريال سعودي', default => $o->currency }, 'client_price' => (float) $o->client_price, 'vendor_cost' => (float) $o->vendor_cost, 'profit' => (float) $o->profit, 'initial_payment' => (float) $o->initial_payment, 'payment_method' => $o->payment_method, 'notes' => $o->notes, 'status' => $o->status, 'created_by' => $o->created_by, 'date' => $o->op_date?->toDateString(), 'client' => $o->client?->name, 'service' => $o->service?->name, 'vendor' => $o->vendor?->name, 'client_outstanding' => $this->accounting->operationClientOutstanding($o->id), 'vendor_outstanding' => $this->accounting->operationVendorOutstanding($o->id)];
    }

    private function voucherPayload(Voucher $v): array
    {
        $operation = $v->relationLoaded('operation') ? $v->operation : ($v->operation_id ? Operation::find($v->operation_id) : null);
        $reversed = $operation?->status === 'cancelled';

        return ['id' => $v->id, 'ref' => $v->ref, 'type' => $v->type, 'party_type' => $v->party_type, 'party_id' => $v->party_id, 'amount' => (float) $v->amount, 'currency' => $v->currency, 'method' => $v->method, 'method_label' => $this->methodLabel($v->method), 'safe_id' => $v->safe_id, 'operation_id' => $v->operation_id, 'desc' => $v->description ?? '', 'description' => $v->description ?? '', 'date' => $v->voucher_date?->toDateString(), 'created_by' => $v->created_by, 'reversed' => $reversed, 'operation_status' => $operation?->status];
    }

    private function journalPayload(JournalEntry $j): array
    {
        return ['id' => $j->id, 'date' => $j->entry_date?->toDateString(), 'ref' => $j->ref, 'operation_id' => $j->operation_id, 'voucher_id' => $j->voucher_id, 'type' => $j->source_type === 'operation' ? 'op' : 'voucher', 'account' => $j->account?->name, 'party' => $j->party_type, 'party_id' => $j->party_id ?? 0, 'party_name' => $j->party_name ?? '', 'debit' => (float) $j->debit, 'credit' => (float) $j->credit, 'desc' => $j->description ?? ''];
    }

    private function methodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'نقد',
            'bank' => 'تحويل بنكي',
            'knet' => 'كي-نت',
            'check', 'cheque' => 'شيك',
            default => $method ?? '—',
        };
    }

    private function reportOperations(): array
    {
        $data = Operation::where('status', '!=', 'cancelled')->orderByDesc('id')->get();

        return ['totals' => ['revenue' => (float) $data->sum('client_price'), 'cost' => (float) $data->sum('vendor_cost'), 'profit' => (float) $data->sum('profit')], 'rows' => $data->map(fn ($o) => $this->operationPayload($o))];
    }

    private function reportProfit(): array
    {
        return ['rows' => Service::orderBy('id')->get()->map(function ($s) {
            $ops = Operation::where('service_id', $s->id)->where('status', '!=', 'cancelled')->get();

            return ['name' => $s->name, 'icon' => $s->icon, 'count' => $ops->count(), 'revenue' => (float) $ops->sum('client_price'), 'cost' => (float) $ops->sum('vendor_cost'), 'profit' => (float) $ops->sum('profit')];
        })->sortByDesc('profit')->values()];
    }

    private function reportAging(): array
    {
        $today = now();
        $rows = Client::all()->map(function ($c) use ($today) {
            $row = ['name' => $c->name, 'balance' => 0.0, 'days' => 0, 'b1' => 0.0, 'b2' => 0.0, 'b3' => 0.0, 'b4' => 0.0];

            Operation::where('client_id', $c->id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('op_date')
                ->get()
                ->each(function ($operation) use (&$row, $today) {
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

    private function reportEmployee(): array
    {
        return ['rows' => User::all()->map(function ($u) {
            $ops = Operation::where('created_by', $u->id)->where('status', '!=', 'cancelled')->get();

            return ['name' => $u->name, 'role' => $u->role_label, 'count' => $ops->count(), 'revenue' => (float) $ops->sum('client_price'), 'profit' => (float) $ops->sum('profit')];
        })->sortByDesc('revenue')->values()];
    }

    private function reportCashflow(): array
    {
        $safes = Safe::orderBy('id')->get();
        $running = $safes->mapWithKeys(fn ($safe) => [$safe->id => (float) $safe->opening_balance])->all();
        $dates = JournalEntry::whereHas('account', fn ($q) => $q->whereNotNull('safe_id'))->pluck('entry_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->sort()->values();

        return [
            'safes' => $safes->map(fn ($safe) => ['id' => $safe->id, 'name' => $safe->name, 'type' => $safe->type])->values(),
            'rows' => $dates->map(function ($d) use (&$running, $safes) {
            $safeEntries = JournalEntry::with('account')->whereDate('entry_date', $d)->whereHas('account', fn ($q) => $q->whereNotNull('safe_id'))->get();
            $in = (float) $safeEntries->sum('debit');
            $out = (float) $safeEntries->sum('credit');
            foreach ($safeEntries as $j) {
                $safeId = (int) $j->account->safe_id;
                $running[$safeId] = ($running[$safeId] ?? 0) + (float) $j->debit - (float) $j->credit;
            }
            $safeBalances = $safes->mapWithKeys(fn ($safe) => [$safe->id => round((float) ($running[$safe->id] ?? 0), 3)]);

            return ['date' => $d, 'inflow' => $in, 'outflow' => $out, 'net' => $in - $out, 'safes' => $safeBalances, 'cash' => (float) ($running[1] ?? 0), 'bank' => (float) ($running[2] ?? 0)];
        })];
    }

    private function reportClientsDebt(): array
    {
        $rows = Client::all()->map(function ($c) {
            $bal = $this->accounting->clientBalance($c->id);
            $ops = Operation::where('client_id', $c->id)->where('status', '!=', 'cancelled');

            return ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'nationality' => $c->nationality, 'totalPurchases' => (float) $ops->sum('client_price'), 'totalPaid' => $this->accounting->clientReceiptsTotal($c->id), 'balance' => $bal, 'opsCount' => $ops->count(), 'lastOpDate' => $ops->orderByDesc('op_date')->value('op_date') ?: '—'];
        })->where('balance', '>', 0)->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalDebt' => (float) $rows->sum('balance')];
    }

    private function reportVendorsBalance(): array
    {
        $rows = Vendor::all()->map(function ($v) {
            $bal = $this->accounting->vendorBalance($v->id);
            $ops = Operation::where('vendor_id', $v->id)->where('status', '!=', 'cancelled');

            return ['id' => $v->id, 'name' => $v->name, 'category' => $v->category, 'phone' => $v->phone, 'contact' => $v->contact, 'totalServices' => (float) $ops->sum('vendor_cost'), 'totalPaid' => $this->accounting->vendorPaymentsTotal($v->id), 'balance' => $bal, 'opsCount' => $ops->count(), 'lastOpDate' => $ops->orderByDesc('op_date')->value('op_date') ?: '—'];
        })->where('balance', '>', 0)->sortByDesc('balance')->values();

        return ['rows' => $rows, 'totalOwed' => (float) $rows->sum('balance')];
    }
}
