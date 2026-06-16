<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherService
{
    public function __construct(private AccountingService $accounting, private ReferenceService $references, private OperationService $operations, private ActivityLogger $activityLogger, private CurrencyService $currencies) {}

    public function create(array $data, int $userId): Voucher
    {
        return DB::transaction(function () use ($data, $userId) {
            $operation = isset($data['operation_id']) ? \App\Models\Operation::find($data['operation_id']) : null;
            $safe = \App\Models\Safe::find($data['safe_id']);
            $currency = isset($data['currency'])
                ? $this->currencies->activeByCode($data['currency'])
                : ($operation ? $this->currencies->byCode($operation->currency) : $this->currencies->officeCurrency(officeId: app(\App\Support\OfficeContext::class)->requireId()));

            if ($operation && $operation->currency !== $currency->code) {
                throw ValidationException::withMessages(['currency' => ['عملة السند يجب أن تطابق عملة العملية المرتبطة.']]);
            }
            if ($safe && $safe->currency !== $currency->code) {
                throw ValidationException::withMessages(['currency' => ['عملة السند يجب أن تطابق عملة الصندوق/البنك.']]);
            }

            $voucher = Voucher::create([
                'ref' => $data['ref'] ?? $this->references->voucherRef($data['type']),
                'type' => $data['type'],
                'party_type' => $data['party_type'] ?? 'general',
                'party_id' => $data['party_id'] ?? null,
                'amount' => $data['amount'],
                'currency' => $currency->code,
                'currency_id' => $currency->id,
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
            $this->activityLogger->log('voucher.created', $voucher, ['ref' => $voucher->ref, 'type' => $voucher->type], $userId);

            return $voucher->fresh(['safe', 'operation']);
        });
    }

    public function void(Voucher $voucher, int $userId): Voucher
    {
        return DB::transaction(function () use ($voucher, $userId) {
            if ($voucher->voided_at) {
                throw ValidationException::withMessages([
                    'voucher' => ['السند ملغى مسبقاً'],
                ]);
            }

            if ($voucher->operation?->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'voucher' => ['لا يمكن إلغاء سند مرتبط بعملية ملغاة'],
                ]);
            }

            $this->accounting->postVoucher($voucher, -1);
            $voucher->update(['voided_at' => now()]);

            if ($voucher->operation_id && $voucher->operation) {
                $this->operations->refreshStatusIfSettled($voucher->operation);
            }

            $this->activityLogger->log('voucher.voided', $voucher, ['ref' => $voucher->ref], $userId);

            return $voucher->fresh(['safe', 'operation']);
        });
    }
}
