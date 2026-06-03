<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationService
{
    public function __construct(private AccountingService $accounting, private ReferenceService $references) {}

    public function create(array $data, int $userId): Operation
    {
        return DB::transaction(function () use ($data, $userId) {
            $operation = Operation::create([
                'ref' => $this->references->operationRef(),
                'client_id' => $data['client_id'],
                'service_id' => $data['service_id'],
                'vendor_id' => $data['vendor_id'],
                'currency' => $data['currency'] ?? 'KWD',
                'client_price' => $data['client_price'],
                'vendor_cost' => $data['vendor_cost'],
                'profit' => (float) $data['client_price'] - (float) $data['vendor_cost'],
                'initial_payment' => $data['initial_payment'] ?? 0,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'status' => 'new',
                'created_by' => $userId,
                'op_date' => $data['date'] ?? now()->toDateString(),
            ]);

            $this->accounting->postOperation($operation);

            if ((float) $operation->initial_payment > 0) {
                $safeId = in_array($operation->payment_method, ['bank', 'knet', 'check'], true) ? 2 : 1;
                $voucher = Voucher::create([
                    'ref' => $this->references->voucherRef('receipt'),
                    'type' => 'receipt',
                    'party_type' => 'client',
                    'party_id' => $operation->client_id,
                    'amount' => $operation->initial_payment,
                    'currency' => $operation->currency,
                    'method' => $operation->payment_method,
                    'safe_id' => $safeId,
                    'operation_id' => $operation->id,
                    'description' => 'دفعة أولى - '.$operation->ref,
                    'voucher_date' => $operation->op_date,
                    'created_by' => $userId,
                ]);
                $this->accounting->postVoucher($voucher);
            }

            return $this->refreshStatusIfSettled($operation)->fresh(['client', 'service', 'vendor', 'vouchers']);
        });
    }

    public function updateStatus(Operation $operation, string $status, User $user): Operation
    {
        return DB::transaction(function () use ($operation, $status, $user) {
            if ($operation->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن تغيير حالة عملية ملغاة'],
                ]);
            }

            if ($status === 'processing') {
                if ($user->role !== 'admin' && $user->role !== 'sales') {
                    throw ValidationException::withMessages(['status' => ['ليست لديك صلاحية نقل العملية إلى قيد التنفيذ']]);
                }
                if ($operation->status !== 'new') {
                    throw ValidationException::withMessages(['status' => ['يمكن نقل العمليات الجديدة فقط إلى قيد التنفيذ']]);
                }
            }

            if ($status === 'completed') {
                if ($user->role !== 'admin' && $user->role !== 'accountant') {
                    throw ValidationException::withMessages(['status' => ['ليست لديك صلاحية إكمال العملية']]);
                }
                if ($operation->status !== 'processing') {
                    throw ValidationException::withMessages(['status' => ['يجب أن تكون العملية قيد التنفيذ قبل إكمالها']]);
                }
                if (! $this->isSettled($operation)) {
                    throw ValidationException::withMessages(['status' => ['لا يمكن إكمال العملية قبل تسوية رصيد العميل والمورد']]);
                }
            }

            $operation->update(['status' => $status]);

            return $operation->fresh(['client', 'service', 'vendor']);
        });
    }

    public function refreshStatusIfSettled(Operation $operation): Operation
    {
        if ($operation->status !== 'cancelled' && $this->isSettled($operation)) {
            $operation->update(['status' => 'completed']);
        }

        return $operation->fresh(['client', 'service', 'vendor']);
    }

    public function cancel(Operation $operation): Operation
    {
        return DB::transaction(function () use ($operation) {
            if ($operation->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'operation' => ['العملية ملغاة مسبقاً'],
                ]);
            }

            $operation->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            $this->accounting->postOperation($operation->fresh(), -1);

            foreach ($operation->vouchers()->get() as $voucher) {
                $this->accounting->postVoucher($voucher, -1);
            }

            return $operation->fresh(['client', 'service', 'vendor']);
        });
    }

    private function isSettled(Operation $operation): bool
    {
        return $this->accounting->operationClientOutstanding($operation->id) <= 0.001
            && $this->accounting->operationVendorOutstanding($operation->id) <= 0.001;
    }
}
