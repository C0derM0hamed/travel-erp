<?php

namespace App\Services;

use App\Models\Operation;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationService
{
    public function __construct(private AccountingService $accounting, private ReferenceService $references, private SafeResolver $safeResolver, private ActivityLogger $activityLogger) {}

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
                $safeId = $this->safeResolver->resolveForPaymentMethod($operation->payment_method);
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

            $operation = $this->refreshStatusIfSettled($operation)->fresh(['client', 'service', 'vendor', 'vouchers']);
            $this->activityLogger->log('operation.created', $operation, ['ref' => $operation->ref], $userId);

            return $operation;
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
                if (! in_array($user->role, ['super_admin', 'admin', 'sales'], true)) {
                    throw ValidationException::withMessages(['status' => ['ليست لديك صلاحية نقل العملية إلى قيد التنفيذ']]);
                }
                if ($operation->status !== 'new') {
                    throw ValidationException::withMessages(['status' => ['يمكن نقل العمليات الجديدة فقط إلى قيد التنفيذ']]);
                }
            }

            if ($status === 'completed') {
                if (! in_array($user->role, ['super_admin', 'admin', 'accountant'], true)) {
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
            $this->activityLogger->log('operation.status_updated', $operation, ['status' => $status], $user->id);

            return $operation->fresh(['client', 'service', 'vendor']);
        });
    }

    public function refreshStatusIfSettled(Operation $operation): Operation
    {
        if ($operation->status === 'processing' && $this->isSettled($operation)) {
            $operation->update(['status' => 'completed']);
        }

        return $operation->fresh(['client', 'service', 'vendor']);
    }

    public function update(Operation $operation, array $data, int $userId): Operation
    {
        return DB::transaction(function () use ($operation, $data, $userId) {
            $operation = $operation->fresh();

            if ($operation->status === 'new' && $this->hasFinancialChanges($data)) {
                $this->reverseOperationAccounting($operation);

                $operation->update($this->operationAttributes($data, $operation));
                $operation = $operation->fresh();

                $this->accounting->postOperation($operation);
                $this->recreateInitialReceipt($operation, $userId);
            } else {
                $operation->update(array_filter([
                    'notes' => $data['notes'] ?? $operation->notes,
                    'op_date' => $data['date'] ?? $operation->op_date,
                ], fn ($value) => $value !== null));
            }

            $this->activityLogger->log('operation.updated', $operation, ['ref' => $operation->ref], $userId);

            return $operation->fresh(['client', 'service', 'vendor', 'vouchers']);
        });
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

            foreach ($operation->vouchers()->whereNull('voided_at')->get() as $voucher) {
                $this->accounting->postVoucher($voucher, -1);
                $voucher->update(['voided_at' => now()]);
            }
            $this->activityLogger->log('operation.cancelled', $operation, ['ref' => $operation->ref]);

            return $operation->fresh(['client', 'service', 'vendor']);
        });
    }

    private function isSettled(Operation $operation): bool
    {
        return $this->accounting->operationClientOutstanding($operation->id) <= 0.001
            && $this->accounting->operationVendorOutstanding($operation->id) <= 0.001;
    }

    private function hasFinancialChanges(array $data): bool
    {
        return array_key_exists('client_id', $data)
            || array_key_exists('service_id', $data)
            || array_key_exists('vendor_id', $data)
            || array_key_exists('client_price', $data)
            || array_key_exists('vendor_cost', $data)
            || array_key_exists('initial_payment', $data)
            || array_key_exists('payment_method', $data)
            || array_key_exists('currency', $data);
    }

    private function operationAttributes(array $data, Operation $operation): array
    {
        $clientPrice = (float) ($data['client_price'] ?? $operation->client_price);
        $vendorCost = (float) ($data['vendor_cost'] ?? $operation->vendor_cost);

        return [
            'client_id' => $data['client_id'] ?? $operation->client_id,
            'service_id' => $data['service_id'] ?? $operation->service_id,
            'vendor_id' => $data['vendor_id'] ?? $operation->vendor_id,
            'currency' => $data['currency'] ?? $operation->currency,
            'client_price' => $clientPrice,
            'vendor_cost' => $vendorCost,
            'profit' => $clientPrice - $vendorCost,
            'initial_payment' => $data['initial_payment'] ?? $operation->initial_payment,
            'payment_method' => $data['payment_method'] ?? $operation->payment_method,
            'notes' => $data['notes'] ?? $operation->notes,
            'op_date' => $data['date'] ?? $operation->op_date,
        ];
    }

    private function reverseOperationAccounting(Operation $operation): void
    {
        $this->accounting->postOperation($operation, -1);

        foreach ($operation->vouchers()->whereNull('voided_at')->get() as $voucher) {
            $this->accounting->postVoucher($voucher, -1);
            $voucher->update(['voided_at' => now()]);
        }
    }

    private function recreateInitialReceipt(Operation $operation, int $userId): void
    {
        if ((float) $operation->initial_payment <= 0) {
            return;
        }

        $safeId = $this->safeResolver->resolveForPaymentMethod($operation->payment_method);
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
}
