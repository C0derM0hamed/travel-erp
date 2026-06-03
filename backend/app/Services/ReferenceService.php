<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ReferenceService
{
    public function operationRef(): string
    {
        return $this->next('operation', 'OP-');
    }

    public function voucherRef(string $type): string
    {
        $key = $type === 'receipt' ? 'voucher_receipt' : 'voucher_payment';
        $prefix = $type === 'receipt' ? 'RV-' : 'PV-';

        return $this->next($key, $prefix);
    }

    private function next(string $key, string $prefix): string
    {
        return DB::transaction(function () use ($key, $prefix) {
            $row = DB::table('reference_sequences')->where('key', $key)->lockForUpdate()->first();

            if (! $row) {
                $this->syncFromExisting();
                $row = DB::table('reference_sequences')->where('key', $key)->lockForUpdate()->first();
            }

            $next = (int) ($row->last_value ?? 0) + 1;
            DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $next]);

            return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }

    public function syncFromExisting(): void
    {
        $this->setSequence('operation', $this->maxRefNumber('operations', 'OP-'));
        $this->setSequence('voucher_receipt', $this->maxRefNumber('vouchers', 'RV-'));
        $this->setSequence('voucher_payment', $this->maxRefNumber('vouchers', 'PV-'));
    }

    private function setSequence(string $key, int $value): void
    {
        DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $value]);
    }

    private function maxRefNumber(string $table, string $prefix): int
    {
        $max = (int) DB::table($table)->max('id');

        foreach (DB::table($table)->pluck('ref') as $ref) {
            if (is_string($ref) && str_starts_with($ref, $prefix)) {
                $max = max($max, (int) substr($ref, strlen($prefix)));
            }
        }

        return $max;
    }
}
