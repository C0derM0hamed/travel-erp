<?php

namespace App\Services;

use App\Models\Safe;
use App\Models\SafeTransfer;
use App\Support\OfficeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SafeTransferService
{
    public function __construct(
        private OfficeContext $officeContext,
        private ReferenceService $references,
        private AccountingService $accounting,
        private CurrencyService $currencies,
    ) {}

    public function create(array $data, int $userId): SafeTransfer
    {
        $officeId = $this->officeContext->requireId();
        $fromSafe = Safe::with('account')->findOrFail($data['from_safe_id']);
        $toSafe = Safe::with('account')->findOrFail($data['to_safe_id']);
        $amount = (float) $data['amount'];

        $this->validateTransfer($fromSafe, $toSafe, $amount, $officeId);

        return DB::transaction(function () use ($data, $userId, $officeId, $fromSafe, $toSafe, $amount) {
            $currency = isset($data['currency'])
                ? $this->currencies->activeByCode($data['currency'])
                : ($this->currencies->byCode($fromSafe->currency) ?? $this->currencies->officeCurrency(officeId: $officeId));

            if ($currency->code !== $fromSafe->currency || $currency->code !== $toSafe->currency) {
                throw ValidationException::withMessages(['currency' => 'عملة التحويل يجب أن تطابق عملة الصندوقين.']);
            }

            $transfer = SafeTransfer::create([
                'office_id' => $officeId,
                'ref' => $this->references->transferRef($officeId),
                'from_safe_id' => $fromSafe->id,
                'to_safe_id' => $toSafe->id,
                'amount' => $amount,
                'currency' => $currency->code,
                'currency_id' => $currency->id,
                'transfer_date' => $data['transfer_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->accounting->postTransfer($transfer);

            return $transfer->fresh(['fromSafe', 'toSafe', 'creator']);
        });
    }

    private function validateTransfer(Safe $fromSafe, Safe $toSafe, float $amount, int $officeId): void
    {
        if ((int) $fromSafe->office_id !== $officeId || (int) $toSafe->office_id !== $officeId) {
            throw ValidationException::withMessages([
                'transfer' => 'لا يمكن التحويل بين صناديق مكاتب مختلفة.',
            ]);
        }

        if ($fromSafe->id === $toSafe->id) {
            throw ValidationException::withMessages([
                'to_safe_id' => 'يجب أن يكون حساب الوجهة مختلفاً عن حساب المصدر.',
            ]);
        }

        if (! $fromSafe->is_active || ! $toSafe->is_active) {
            throw ValidationException::withMessages([
                'transfer' => 'يجب أن يكون الصندوق المصدر والوجهة مفعّلين.',
            ]);
        }

        if (! $fromSafe->account || ! $toSafe->account) {
            throw ValidationException::withMessages([
                'transfer' => 'حساب محاسبي مفقود لأحد الصناديق.',
            ]);
        }

        if ($fromSafe->currency !== $toSafe->currency) {
            throw ValidationException::withMessages([
                'transfer' => 'يجب أن تتطابق عملة الصندوقين.',
            ]);
        }

        $available = $this->accounting->safeBalance($fromSafe->id, $officeId);
        if ($amount > $available + 0.001) {
            throw ValidationException::withMessages([
                'amount' => 'رصيد الصندوق المصدر غير كافٍ للتحويل.',
            ]);
        }
    }
}
