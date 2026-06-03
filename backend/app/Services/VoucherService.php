<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

class VoucherService
{
    public function __construct(private AccountingService $accounting, private ReferenceService $references, private OperationService $operations) {}

    public function create(array $data, int $userId): Voucher
    {
        return DB::transaction(function () use ($data, $userId) {
            $voucher = Voucher::create([
                'ref' => $data['ref'] ?? $this->references->voucherRef($data['type']),
                'type' => $data['type'],
                'party_type' => $data['party_type'] ?? 'general',
                'party_id' => $data['party_id'] ?? null,
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'KWD',
                'method' => $data['method'] ?? 'cash',
                'safe_id' => $data['safe_id'],
                'operation_id' => $data['operation_id'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'description' => $data['description'] ?? null,
                'voucher_date' => $data['date'] ?? now()->toDateString(),
                'created_by' => $userId,
            ]);
            $this->accounting->postVoucher($voucher);
            if ($voucher->operation_id && $voucher->operation) {
                $this->operations->refreshStatusIfSettled($voucher->operation);
            }

            return $voucher->fresh(['safe', 'operation']);
        });
    }
}
