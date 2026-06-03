<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Vendor;
use App\Models\Voucher;

class AccountingService
{
    public function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    public function postOperation(Operation $operation, int $multiplier = 1): void
    {
        $operation->load(['client', 'service', 'vendor']);
        $descriptionBase = "{$operation->ref} - ".($operation->service?->name ?? '').' - '.($operation->client?->name ?? '');

        $this->line($operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('1100'), 'client', $operation->client_id, $operation->client?->name, $multiplier * (float) $operation->client_price, 0, $descriptionBase);
        $this->line($operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('4100'), 'none', null, null, 0, $multiplier * (float) $operation->client_price, "{$operation->ref} - إيراد ".($operation->service?->name ?? ''));
        $this->line($operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('5100'), 'none', null, null, $multiplier * (float) $operation->vendor_cost, 0, "{$operation->ref} - تكلفة ".($operation->vendor?->name ?? ''));
        $this->line($operation->op_date->toDateString(), $operation->ref, 'operation', $operation->id, $operation->id, null, $this->account('2100'), 'vendor', $operation->vendor_id, $operation->vendor?->name, 0, $multiplier * (float) $operation->vendor_cost, "{$operation->ref} - مستحقات ".($operation->vendor?->name ?? ''));
    }

    public function postVoucher(Voucher $voucher, int $multiplier = 1): void
    {
        $voucher->load(['safe.account']);
        $safeAccount = $voucher->safe->account;
        $partyAccount = match ($voucher->party_type) {
            'client' => $this->account('1100'),
            'vendor' => $this->account('2100'),
            default => $this->account('9999'),
        };
        [$partyName, $partyId] = $this->party($voucher);
        $amount = $multiplier * (float) $voucher->amount;

        if ($voucher->type === 'receipt') {
            $this->line($voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $safeAccount, $voucher->party_type, $partyId, $partyName, $amount, 0, $voucher->description);
            $this->line($voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $partyAccount, $voucher->party_type, $partyId, $partyName, 0, $amount, $voucher->description);

            return;
        }

        $this->line($voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $partyAccount, $voucher->party_type, $partyId, $partyName, $amount, 0, $voucher->description);
        $this->line($voucher->voucher_date->toDateString(), $voucher->ref, 'voucher', $voucher->id, $voucher->operation_id, $voucher->id, $safeAccount, $voucher->party_type, $partyId, $partyName, 0, $amount, $voucher->description);
    }

    public function clientBalance(int $clientId): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')->where('party_id', $clientId)
            ->selectRaw('COALESCE(SUM(debit-credit),0) as bal')->value('bal');
    }

    public function vendorBalance(int $vendorId): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')->where('party_id', $vendorId)
            ->selectRaw('COALESCE(SUM(credit-debit),0) as bal')->value('bal');
    }

    public function operationClientOutstanding(int $operationId): float
    {
        $operation = Operation::find($operationId);
        if (! $operation || $operation->status === 'cancelled') {
            return 0.0;
        }

        return (float) JournalEntry::where('operation_id', $operationId)
            ->whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->selectRaw('COALESCE(SUM(debit-credit),0) as bal')->value('bal');
    }

    public function operationVendorOutstanding(int $operationId): float
    {
        $operation = Operation::find($operationId);
        if (! $operation || $operation->status === 'cancelled') {
            return 0.0;
        }

        return (float) JournalEntry::where('operation_id', $operationId)
            ->whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->selectRaw('COALESCE(SUM(credit-debit),0) as bal')->value('bal');
    }

    public function clientReceiptsTotal(int $clientId): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')->where('party_id', $clientId)
            ->selectRaw('COALESCE(SUM(credit),0) as total')->value('total');
    }

    public function vendorPaymentsTotal(int $vendorId): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')->where('party_id', $vendorId)
            ->selectRaw('COALESCE(SUM(debit),0) as total')->value('total');
    }

    public function totalClientReceipts(): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')
            ->sum('credit');
    }

    public function totalCashReceipts(): float
    {
        return (float) JournalEntry::where('source_type', 'voucher')
            ->whereHas('account', fn ($q) => $q->whereNotNull('safe_id'))
            ->sum('debit');
    }

    public function totalVendorPayments(): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->sum('debit');
    }

    public function clientReceiptsOnDate(string $date): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '1100'))
            ->where('party_type', 'client')
            ->whereDate('entry_date', $date)
            ->sum('credit');
    }

    public function vendorPaymentsOnDate(string $date): float
    {
        return (float) JournalEntry::whereHas('account', fn ($q) => $q->where('code', '2100'))
            ->where('party_type', 'vendor')
            ->whereDate('entry_date', $date)
            ->sum('debit');
    }

    public function safeBalance(int $safeId): float
    {
        $safe = Safe::with('account')->findOrFail($safeId);
        $movement = JournalEntry::where('account_id', $safe->account->id)->selectRaw('COALESCE(SUM(debit-credit),0) as bal')->value('bal');

        return (float) $safe->opening_balance + (float) $movement;
    }

    public function journalQuery()
    {
        return JournalEntry::with('account')->orderBy('entry_date')->orderBy('id');
    }

    public function isJournalBalanced(): bool
    {
        $debit = (float) JournalEntry::sum('debit');
        $credit = (float) JournalEntry::sum('credit');

        return abs($debit - $credit) < 0.01;
    }

    private function party(Voucher $voucher): array
    {
        if ($voucher->party_type === 'client') {
            $client = Client::find($voucher->party_id);

            return [$client?->name ?? '', $client?->id];
        }
        if ($voucher->party_type === 'vendor') {
            $vendor = Vendor::find($voucher->party_id);

            return [$vendor?->name ?? '', $vendor?->id];
        }

        return ['', null];
    }

    private function line(string $date, string $ref, string $sourceType, int $sourceId, ?int $operationId, ?int $voucherId, ChartOfAccount $account, string $partyType, ?int $partyId, ?string $partyName, float $debit, float $credit, ?string $description): void
    {
        JournalEntry::create([
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
            'description' => $description,
        ]);
    }
}
