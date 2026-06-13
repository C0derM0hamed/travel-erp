<?php

namespace App\Services;

use App\Support\OfficeContext;
use Illuminate\Support\Facades\DB;

class ReferenceService
{
    public function __construct(private OfficeContext $officeContext) {}

    public function operationRef(?int $officeId = null): string
    {
        return $this->next($officeId, 'operation', 'OP-');
    }

    public function voucherRef(string $type, ?int $officeId = null): string
    {
        $key = $type === 'receipt' ? 'voucher_receipt' : 'voucher_payment';
        $prefix = $type === 'receipt' ? 'RV-' : 'PV-';

        return $this->next($officeId, $key, $prefix);
    }

    private function next(?int $officeId, string $key, string $prefix): string
    {
        $officeId ??= $this->officeContext->requireId();

        return DB::transaction(function () use ($officeId, $key, $prefix) {
            $row = DB::table('reference_sequences')
                ->where('office_id', $officeId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $this->syncFromExisting($officeId);
                $row = DB::table('reference_sequences')
                    ->where('office_id', $officeId)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();
            }

            $next = (int) ($row->last_value ?? 0) + 1;
            DB::table('reference_sequences')->updateOrInsert(
                ['office_id' => $officeId, 'key' => $key],
                ['last_value' => $next]
            );

            return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }

    public function syncFromExisting(?int $officeId = null): void
    {
        $officeId ??= $this->officeContext->requireId();

        $this->setSequence($officeId, 'operation', $this->maxRefNumber($officeId, 'operations', 'OP-'));
        $this->setSequence($officeId, 'voucher_receipt', $this->maxRefNumber($officeId, 'vouchers', 'RV-'));
        $this->setSequence($officeId, 'voucher_payment', $this->maxRefNumber($officeId, 'vouchers', 'PV-'));
    }

    private function setSequence(int $officeId, string $key, int $value): void
    {
        DB::table('reference_sequences')->updateOrInsert(
            ['office_id' => $officeId, 'key' => $key],
            ['last_value' => $value]
        );
    }

    private function maxRefNumber(int $officeId, string $table, string $prefix): int
    {
        $max = 0;

        foreach (DB::table($table)->where('office_id', $officeId)->pluck('ref') as $ref) {
            if (is_string($ref) && str_starts_with($ref, $prefix)) {
                $max = max($max, (int) substr($ref, strlen($prefix)));
            }
        }

        return $max;
    }
}
