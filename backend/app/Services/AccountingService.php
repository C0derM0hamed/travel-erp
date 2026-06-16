<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\SafeTransfer;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Support\OfficeContext;

class AccountingService
{
    public function __construct(private OfficeContext $officeContext) {}

    public function account(string $code, ?int $officeId = null): ChartOfAccount
    {
        $officeId ??= $this->officeContext->requireId();

        return ChartOfAccount::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('code', $code)
            ->firstOrFail();
    }

    public function postOperation(Operation $operation, int $multiplier = 1): void
    {
        $operation->load(['client', 'service', 'vendor']);
        $officeId = (int) $operation->office_id;
        $descriptionBase = "{$operation->ref} - ".($operation->service?->name ?? '').' - '.($operation->client?->name ?? '');

        $this->line($officeId, $operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('1100', $officeId), 'client', $operation->client_id, $operation->client?->name, $multiplier * (float) $operation->client_price, 0, $descriptionBase, $operation->currency, $operation->currency_id);
        $this->line($officeId, $operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('4100', $officeId), 'none', null, null, 0, $multiplier * (float) $operation->client_price, "{$operation->ref} - إيراد ".($operation->service?->name ?? ''), $operation->currency, $operation->currency_id);
        $this->line($officeId, $operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('5100', $officeId), 'none', null, null, $multiplier * (float) $operation->vendor_cost, 0, "{$operation->ref} - تكلفة ".($operation->vendor?->name ?? ''), $operation->currency, $operation->currency_id);
        $this->line($officeId, $operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('2100', $officeId), 'vendor', $operation->vendor_id, $operation->vendor?->name, 0, $multiplier * (float) $operation->vendor_cost, "{$operation->ref} - مستحقات ".($operation->vendor?->name ?? ''), $operation->currency, $operation->currency_id);
    }

    public function postVoucher(Voucher $voucher, int $multiplier = 1): void
    {
        $voucher->load(['safe.account']);
        $officeId = (int) $voucher->office_id;
        $safeAccount = $voucher->safe->account;
        $partyAccount = match ($voucher->party_type) {
            'client' => $this->account('1100', $officeId),
            'vendor' => $this->account('2100', $officeId),
            default => $this->account('9999', $officeId),
        };
        [$partyName, $partyId] = $this->party($voucher);
        $amount = $multiplier * (float) $voucher->amount;

        if ($voucher->type === 'receipt') {
            $this->line($officeId, $voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $safeAccount, $voucher->party_type, $partyId, $partyName, $amount, 0, $voucher->description, $voucher->currency, $voucher->currency_id);
            $this->line($officeId, $voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $partyAccount, $voucher->party_type, $partyId, $partyName, 0, $amount, $voucher->description, $voucher->currency, $voucher->currency_id);

            return;
        }

        $this->line($officeId, $voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $partyAccount, $voucher->party_type, $partyId, $partyName, $amount, 0, $voucher->description, $voucher->currency, $voucher->currency_id);
        $this->line($officeId, $voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $safeAccount, $voucher->party_type, $partyId, $partyName, 0, $amount, $voucher->description, $voucher->currency, $voucher->currency_id);
    }

    public function postTransfer(SafeTransfer $transfer, int $multiplier = 1): void
    {
        $transfer->load(['fromSafe.account', 'toSafe.account']);
        $officeId = (int) $transfer->office_id;
        $amount = $multiplier * (float) $transfer->amount;
        $date = $transfer->transfer_date->toDateString();
        $from = $transfer->fromSafe;
        $to = $transfer->toSafe;
        $description = $transfer->notes ?: "تحويل من {$from->name} إلى {$to->name}";

        $this->line($officeId, $date, $transfer->ref, 'transfer', $transfer->id, null, null, $to->account, 'none', null, null, $amount, 0, $description, $transfer->currency, $transfer->currency_id);
        $this->line($officeId, $date, $transfer->ref, 'transfer', $transfer->id, null, null, $from->account, 'none', null, null, 0, $amount, $description, $transfer->currency, $transfer->currency_id);
    }

    public function clientBalance(int $clientId, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')->where('party_id', $clientId)
            ->sum(\Illuminate\Support\Facades\DB::raw('debit - credit'));
    }

    /** @return list<array{code:string,name:string,symbol:string,balance:float}> */
    public function clientBalanceByCurrency(int $clientId, ?int $officeId = null): array
    {
        return $this->partyBalanceByCurrency('client', $clientId, '1100', 'debit - credit', $officeId);
    }

    /** @return list<array{code:string,name:string,symbol:string,purchases:float,paid:float,balance:float}> */
    public function clientStatementSummary(int $clientId, ?int $officeId = null, ?string $from = null, ?string $to = null): array
    {
        $officeId ??= $this->officeContext->requireId();
        $currencies = app(CurrencyService::class);
        $defaultCode = strtoupper($currencies->officeCurrency(officeId: $officeId)->code);

        $opsQuery = Operation::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('status', '!=', 'cancelled');
        if ($from) {
            $opsQuery->whereDate('op_date', '>=', $from);
        }
        if ($to) {
            $opsQuery->whereDate('op_date', '<=', $to);
        }

        $purchasesByCode = $opsQuery->get()->groupBy(
            fn (Operation $operation) => strtoupper($operation->currency ?: $defaultCode)
        )->map(fn ($group) => (float) $group->sum('client_price'));

        $entries = $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')
            ->where('party_id', $clientId)
            ->get();

        $paidByCode = $entries->groupBy(
            fn (JournalEntry $journal) => strtoupper($journal->currency ?: $defaultCode)
        )->map(fn ($group) => (float) $group->sum('credit'));

        $balanceByCode = $entries->groupBy(
            fn (JournalEntry $journal) => strtoupper($journal->currency ?: $defaultCode)
        )->map(fn ($group) => (float) $group->sum(fn (JournalEntry $journal) => (float) $journal->debit - (float) $journal->credit));

        return $this->buildStatementSummary($currencies, $officeId, [
            'purchases' => $purchasesByCode,
            'paid' => $paidByCode,
            'balance' => $balanceByCode,
        ]);
    }

    public function vendorBalance(int $vendorId, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')->where('party_id', $vendorId)
            ->sum(\Illuminate\Support\Facades\DB::raw('credit - debit'));
    }

    /** @return list<array{code:string,name:string,symbol:string,balance:float}> */
    public function vendorBalanceByCurrency(int $vendorId, ?int $officeId = null): array
    {
        return $this->partyBalanceByCurrency('vendor', $vendorId, '2100', 'credit - debit', $officeId);
    }

    /** @return list<array{code:string,name:string,symbol:string,credits:float,paid:float,balance:float}> */
    public function vendorStatementSummary(int $vendorId, ?int $officeId = null, ?string $from = null, ?string $to = null): array
    {
        $officeId ??= $this->officeContext->requireId();
        $currencies = app(CurrencyService::class);
        $defaultCode = strtoupper($currencies->officeCurrency(officeId: $officeId)->code);

        $periodQuery = $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->where('party_id', $vendorId);
        if ($from) {
            $periodQuery->whereDate('entry_date', '>=', $from);
        }
        if ($to) {
            $periodQuery->whereDate('entry_date', '<=', $to);
        }

        $periodEntries = $periodQuery->get();
        $creditsByCode = $periodEntries->groupBy(
            fn (JournalEntry $journal) => strtoupper($journal->currency ?: $defaultCode)
        )->map(fn ($group) => (float) $group->sum('credit'));

        $entries = $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->where('party_id', $vendorId)
            ->get();

        $paidByCode = $entries->groupBy(
            fn (JournalEntry $journal) => strtoupper($journal->currency ?: $defaultCode)
        )->map(fn ($group) => (float) $group->sum('debit'));

        $balanceByCode = $entries->groupBy(
            fn (JournalEntry $journal) => strtoupper($journal->currency ?: $defaultCode)
        )->map(fn ($group) => (float) $group->sum(fn (JournalEntry $journal) => (float) $journal->credit - (float) $journal->debit));

        return $this->buildStatementSummary($currencies, $officeId, [
            'credits' => $creditsByCode,
            'paid' => $paidByCode,
            'balance' => $balanceByCode,
        ]);
    }

    public function operationClientOutstanding(int $operationId, ?int $officeId = null): float
    {
        $operation = Operation::withoutGlobalScopes()->find($operationId);
        if (! $operation || $operation->status === 'cancelled') {
            return 0.0;
        }

        $officeId ??= (int) $operation->office_id;

        return (float) $this->journalQuery($officeId)
            ->where('operation_id', $operationId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->sum(\Illuminate\Support\Facades\DB::raw('debit - credit'));
    }

    public function operationVendorOutstanding(int $operationId, ?int $officeId = null): float
    {
        $operation = Operation::withoutGlobalScopes()->find($operationId);
        if (! $operation || $operation->status === 'cancelled') {
            return 0.0;
        }

        $officeId ??= (int) $operation->office_id;

        return (float) $this->journalQuery($officeId)
            ->where('operation_id', $operationId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->sum(\Illuminate\Support\Facades\DB::raw('credit - debit'));
    }

    public function clientReceiptsTotal(int $clientId, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')->where('party_id', $clientId)
            ->sum('credit');
    }

    public function vendorPaymentsTotal(int $vendorId, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')->where('party_id', $vendorId)
            ->sum('debit');
    }

    public function totalClientReceipts(?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')
            ->sum('credit');
    }

    public function totalCashReceipts(?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->where('source_type', 'voucher')
            ->whereHas('account', fn ($q) => $q->whereNotNull('safe_id'))
            ->sum('debit');
    }

    public function totalVendorPayments(?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->sum('debit');
    }

    public function clientReceiptsOnDate(string $date, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')
            ->whereDate('entry_date', $date)
            ->sum('credit');
    }

    public function vendorPaymentsOnDate(string $date, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();

        return (float) $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->whereDate('entry_date', $date)
            ->sum('debit');
    }

    public function safeBalance(int $safeId, ?int $officeId = null): float
    {
        $officeId ??= $this->officeContext->requireId();
        $safe = Safe::withoutGlobalScopes()->with('account')->where('office_id', $officeId)->findOrFail($safeId);
        $movement = (float) $this->journalQuery($officeId)
            ->where('account_id', $safe->account->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('debit - credit'));

        return (float) $safe->opening_balance + (float) $movement;
    }

    public function journalQuery(?int $officeId = null)
    {
        $officeId ??= $this->officeContext->requireId();

        return JournalEntry::withoutGlobalScopes()
            ->with('account')
            ->where('office_id', $officeId)
            ->orderBy('entry_date')
            ->orderBy('id');
    }

    public function isJournalBalanced(?int $officeId = null): bool
    {
        $officeId ??= $this->officeContext->requireId();
        $debit = (float) JournalEntry::withoutGlobalScopes()->where('office_id', $officeId)->sum('debit');
        $credit = (float) JournalEntry::withoutGlobalScopes()->where('office_id', $officeId)->sum('credit');

        return abs($debit - $credit) < 0.01;
    }

    private function party(Voucher $voucher): array
    {
        if ($voucher->party_type === 'client') {
            $client = Client::withoutGlobalScopes()->find($voucher->party_id);

            return [$client?->name ?? '', $client?->id];
        }
        if ($voucher->party_type === 'vendor') {
            $vendor = Vendor::withoutGlobalScopes()->find($voucher->party_id);

            return [$vendor?->name ?? '', $vendor?->id];
        }

        return ['', null];
    }

    /** @return list<array{code:string,name:string,symbol:string,balance:float}> */
    private function partyBalanceByCurrency(string $partyType, int $partyId, string $accountCode, string $balanceExpression, ?int $officeId = null): array
    {
        $officeId ??= $this->officeContext->requireId();
        $currencies = app(CurrencyService::class);
        $defaultCode = strtoupper($currencies->officeCurrency(officeId: $officeId)->code);

        $entries = $this->journalQuery($officeId)
            ->whereHas('account', fn ($q) => $q->where('code', $accountCode))
            ->where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->get();

        return $entries->groupBy(
            fn (JournalEntry $journal) => strtoupper($journal->currency ?: $defaultCode)
        )->map(function ($group, string $code) use ($currencies, $officeId, $balanceExpression) {
            $payload = $currencies->payloadForCode($code, $officeId);
            $payload['balance'] = round((float) $group->sum(
                fn (JournalEntry $journal) => $balanceExpression === 'credit - debit'
                    ? (float) $journal->credit - (float) $journal->debit
                    : (float) $journal->debit - (float) $journal->credit
            ), 3);

            return $payload;
        })->values()->filter(fn (array $row) => abs($row['balance']) > 0.0005)->values()->all();
    }

    /** @param array<string, \Illuminate\Support\Collection<string, float>> $fieldsByCode */
    private function buildStatementSummary(CurrencyService $currencies, int $officeId, array $fieldsByCode): array
    {
        $codes = collect($fieldsByCode)
            ->flatMap(fn ($values) => $values->keys())
            ->unique()
            ->sort()
            ->values();

        return $codes->map(function (string $code) use ($currencies, $officeId, $fieldsByCode) {
            $payload = $currencies->payloadForCode($code, $officeId);
            foreach ($fieldsByCode as $field => $values) {
                $payload[$field] = round((float) ($values->get($code) ?? 0), 3);
            }

            return $payload;
        })->filter(function (array $row) use ($fieldsByCode) {
            foreach (array_keys($fieldsByCode) as $field) {
                if (abs($row[$field] ?? 0) > 0.0005) {
                    return true;
                }
            }

            return false;
        })->values()->all();
    }

    private function line(int $officeId, string $date, string $ref, string $sourceType, int $sourceId, ?int $operationId, ?int $voucherId, ChartOfAccount $account, string $partyType, ?int $partyId, ?string $partyName, float $debit, float $credit, ?string $description, ?string $currency = null, ?int $currencyId = null): void
    {
        JournalEntry::withoutGlobalScopes()->create([
            'office_id' => $officeId,
            'entry_date' => $date,
            'ref' => $ref,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'operation_id' => $operationId,
            'voucher_id' => $voucherId,
            'account_id' => $account->id,
            'party_type' => $partyType ?: 'none',
            'party_id' => $partyId,
            'party_name' => $partyName,
            'debit' => $debit,
            'credit' => $credit,
            'currency' => $currency,
            'currency_id' => $currencyId,
            'description' => $description,
        ]);
    }
}
