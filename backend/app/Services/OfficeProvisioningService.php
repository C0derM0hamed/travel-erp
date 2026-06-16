<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Office;
use App\Models\Safe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficeProvisioningService
{
    public function createOffice(array $data): Office
    {
        return DB::transaction(function () use ($data) {
            $office = Office::create([
                'office_code' => $data['office_code'],
                'office_name' => $data['office_name'],
                'logo' => $data['logo'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'default_currency_id' => $data['default_currency_id'] ?? app(CurrencyService::class)->defaultCurrency()->id,
            ]);

            $this->ensureOfficeProvisioned((int) $office->id);

            return $office->fresh();
        });
    }

    public function ensureOfficeProvisioned(int $officeId): void
    {
        $office = Office::withoutGlobalScopes()->find($officeId);
        if (! $office) {
            throw ValidationException::withMessages([
                'office' => 'المكتب غير موجود.',
            ]);
        }

        DB::transaction(function () use ($office) {
            $currency = app(CurrencyService::class)->officeCurrency(office: $office);

            $cashSafe = $this->ensureDefaultSafe($office->id, 'cash', 'الصندوق الرئيسي', $currency);
            $bankSafe = $this->ensureDefaultSafe($office->id, 'bank', 'البنك', $currency);

            $this->ensureStandardChartAccounts($office->id, $cashSafe, $bankSafe);
            $this->ensureReferenceSequences($office->id);
        });
    }

    private function ensureDefaultSafe(int $officeId, string $type, string $defaultName, \App\Models\Currency $currency): Safe
    {
        $safe = Safe::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($safe) {
            return $safe;
        }

        return Safe::withoutGlobalScopes()->create([
            'office_id' => $officeId,
            'name' => $defaultName,
            'type' => $type,
            'currency' => $currency->code,
            'currency_id' => $currency->id,
            'opening_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function ensureStandardChartAccounts(int $officeId, Safe $cashSafe, Safe $bankSafe): void
    {
        $accounts = [
            ['1100', 'ذمم العملاء', 'asset', null],
            ['2100', 'ذمم الموردين', 'liability', null],
            ['4100', 'إيرادات الخدمات', 'revenue', null],
            ['5100', 'تكلفة الخدمات', 'expense', null],
            ['1001', 'الصندوق الرئيسي', 'asset', $cashSafe->id],
            ['1002', 'البنك', 'asset', $bankSafe->id],
            ['9999', 'حساب عام', 'asset', null],
        ];

        foreach ($accounts as [$code, $name, $type, $safeId]) {
            $account = ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
                ['office_id' => $officeId, 'code' => $code],
                ['name' => $name, 'type' => $type, 'safe_id' => $safeId],
            );

            if ($safeId && ! $account->safe_id) {
                $account->update(['safe_id' => $safeId]);
            }
        }
    }

    private function ensureReferenceSequences(int $officeId): void
    {
        foreach (['operation', 'voucher_receipt', 'voucher_payment', 'safe_transfer'] as $key) {
            if (DB::table('reference_sequences')->where('office_id', $officeId)->where('key', $key)->exists()) {
                continue;
            }

            DB::table('reference_sequences')->insert([
                'office_id' => $officeId,
                'key' => $key,
                'last_value' => 0,
            ]);
        }
    }
}
