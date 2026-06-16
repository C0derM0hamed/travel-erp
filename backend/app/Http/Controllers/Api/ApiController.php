<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Services\CurrencyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    public function __construct(protected AccountingService $accounting) {}

    protected function paginatedResponse(Request $request, Builder $query, callable $mapper): JsonResponse
    {
        $paginated = $this->paginate($request, $query);

        return response()->json([
            'data' => $paginated->getCollection()->map($mapper)->values(),
            'meta' => $this->paginationMeta($paginated),
        ]);
    }

    protected function paginate(Request $request, Builder $query): LengthAwarePaginator
    {
        $perPage = min(max((int) $request->query('per_page', 25), 1), 500);

        return $query->paginate($perPage)->appends($request->query());
    }

    protected function paginationMeta(LengthAwarePaginator $paginated): array
    {
        return [
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
        ];
    }

    protected function applyHiddenFilter(Request $request, Builder $query): Builder
    {
        if ($request->boolean('hidden')) {
            return $query->hidden();
        }

        return $query->visible();
    }

    protected function metricsPayload(): array
    {
        return [
            'total_receipts' => $this->accounting->totalClientReceipts(),
            'total_cash_receipts' => $this->accounting->totalCashReceipts(),
            'total_payments' => $this->accounting->totalVendorPayments(),
            'journal_count' => JournalEntry::count(),
            'journal_balanced' => $this->accounting->isJournalBalanced(),
        ];
    }

    protected function userPayload(User $user): array
    {
        $office = $user->relationLoaded('office') ? $user->office : $user->office()->first();
        $context = app(\App\Support\OfficeContext::class);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'roleLabel' => $user->role_label ?: $user->role,
            'avatar' => $user->avatar,
            'must_change_password' => (bool) ($user->must_change_password ?? false),
            'is_active' => (bool) ($user->is_active ?? true),
            'office_id' => $user->office_id,
            'office' => $office ? [
                'id' => $office->id,
                'office_code' => $office->office_code,
                'office_name' => $office->office_name,
                'logo' => $office->logo,
                'logo_url' => app(\App\Services\OfficeLogoService::class)->url($office->logo),
                'is_active' => (bool) $office->is_active,
                'default_currency_id' => $office->default_currency_id,
                'default_currency' => $this->currencyPayloadForCode($office->defaultCurrency?->code),
            ] : null,
            'current_office_id' => $context->id(),
        ];
    }

    protected function officePayload(\App\Models\Office $office): array
    {
        $logos = app(\App\Services\OfficeLogoService::class);

        return [
            'id' => $office->id,
            'office_code' => $office->office_code,
            'office_name' => $office->office_name,
            'logo' => $office->logo,
            'logo_url' => $logos->url($office->logo),
            'is_active' => (bool) $office->is_active,
            'default_currency_id' => $office->default_currency_id,
            'default_currency' => $this->currencyPayloadForCode($office->defaultCurrency?->code, $office->id),
        ];
    }

    protected function clientPayload(Client $client): array
    {
        $data = $client->toArray();
        $data['nationality'] = $data['nationality'] ?? '';

        return $data + [
            'balance' => $this->accounting->clientBalance($client->id),
            'balance_by_currency' => $this->accounting->clientBalanceByCurrency($client->id),
            'operations_count' => Operation::where('client_id', $client->id)->where('status', '!=', 'cancelled')->count(),
        ];
    }

    protected function vendorPayload(Vendor $vendor): array
    {
        return $vendor->toArray() + [
            'balance' => $this->accounting->vendorBalance($vendor->id),
            'balance_by_currency' => $this->accounting->vendorBalanceByCurrency($vendor->id),
        ];
    }

    protected function safePayload(Safe $safe): array
    {
        return [
            'id' => $safe->id,
            'name' => $safe->name,
            'type' => $safe->type,
            'currency' => $safe->currency,
            'currency_symbol' => $this->currencySymbol($safe->currency, $safe->office_id),
            'currency_meta' => $this->currencyPayloadForCode($safe->currency, $safe->office_id),
            'initial' => (float) $safe->opening_balance,
            'opening_balance' => (float) $safe->opening_balance,
            'is_active' => (bool) ($safe->is_active ?? true),
        ];
    }

    protected function operationPayload(Operation $operation): array
    {
        return [
            'id' => $operation->id,
            'ref' => $operation->ref,
            'client_id' => $operation->client_id,
            'service_id' => $operation->service_id,
            'vendor_id' => $operation->vendor_id,
            'currency' => $operation->currency,
            'currency_id' => $operation->currency_id,
            'currency_label' => $this->currencyName($operation->currency, $operation->office_id),
            'currency_symbol' => $this->currencySymbol($operation->currency, $operation->office_id),
            'currency_meta' => $this->currencyPayloadForCode($operation->currency, $operation->office_id),
            'client_price' => (float) $operation->client_price,
            'vendor_cost' => (float) $operation->vendor_cost,
            'profit' => (float) $operation->profit,
            'initial_payment' => (float) $operation->initial_payment,
            'payment_method' => $operation->payment_method,
            'notes' => $operation->notes,
            'status' => $operation->status,
            'is_hidden' => (bool) $operation->is_hidden,
            'created_by' => $operation->created_by,
            'date' => $operation->op_date?->toDateString(),
            'client' => $operation->client?->name,
            'service' => $operation->service?->name,
            'vendor' => $operation->vendor?->name,
            'client_outstanding' => $this->accounting->operationClientOutstanding($operation->id),
            'vendor_outstanding' => $this->accounting->operationVendorOutstanding($operation->id),
        ];
    }

    protected function voucherPayload(Voucher $voucher): array
    {
        $operation = $voucher->relationLoaded('operation')
            ? $voucher->operation
            : ($voucher->operation_id ? Operation::find($voucher->operation_id) : null);
        $reversed = $voucher->voided_at !== null || $operation?->status === 'cancelled';

        return [
            'id' => $voucher->id,
            'ref' => $voucher->ref,
            'type' => $voucher->type,
            'party_type' => $voucher->party_type,
            'party_id' => $voucher->party_id,
            'amount' => (float) $voucher->amount,
            'currency' => $voucher->currency,
            'currency_id' => $voucher->currency_id,
            'currency_symbol' => $this->currencySymbol($voucher->currency, $voucher->office_id),
            'currency_meta' => $this->currencyPayloadForCode($voucher->currency, $voucher->office_id),
            'method' => $voucher->method,
            'method_label' => $this->methodLabel($voucher->method),
            'safe_id' => $voucher->safe_id,
            'operation_id' => $voucher->operation_id,
            'desc' => $voucher->description ?? '',
            'description' => $voucher->description ?? '',
            'date' => $voucher->voucher_date?->toDateString(),
            'created_by' => $voucher->created_by,
            'reversed' => $reversed,
            'voided_at' => $voucher->voided_at?->toIso8601String(),
            'operation_status' => $operation?->status,
        ];
    }

    protected function journalPayload(JournalEntry $journal): array
    {
        return [
            'id' => $journal->id,
            'date' => $journal->entry_date?->toDateString(),
            'ref' => $journal->ref,
            'operation_id' => $journal->operation_id,
            'voucher_id' => $journal->voucher_id,
            'type' => $journal->source_type === 'operation' ? 'op' : 'voucher',
            'account' => $journal->account?->name,
            'party' => $journal->party_type,
            'party_id' => $journal->party_id ?? 0,
            'party_name' => $journal->party_name ?? '',
            'debit' => (float) $journal->debit,
            'credit' => (float) $journal->credit,
            'currency' => $journal->currency,
            'currency_id' => $journal->currency_id,
            'currency_symbol' => $this->currencySymbol($journal->currency, $journal->office_id),
            'currency_meta' => $this->currencyPayloadForCode($journal->currency, $journal->office_id),
            'desc' => $journal->description ?? '',
        ];
    }

    protected function currencyPayloadForCode(?string $code, ?int $officeId = null): ?array
    {
        return app(CurrencyService::class)->payloadForCode($code, $officeId);
    }

    protected function currencySymbol(?string $code, ?int $officeId = null): string
    {
        return $this->currencyPayloadForCode($code, $officeId)['symbol'] ?? ($code ?: '');
    }

    protected function currencyName(?string $code, ?int $officeId = null): string
    {
        return $this->currencyPayloadForCode($code, $officeId)['name'] ?? ($code ?: '');
    }

    protected function methodLabel(?string $method): string
    {
        return match ($method) {
            'cash' => 'نقد',
            'bank' => 'تحويل بنكي',
            'knet' => 'كي-نت',
            'check', 'cheque' => 'شيك',
            default => $method ?? '—',
        };
    }
}
