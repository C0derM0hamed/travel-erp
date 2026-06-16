<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Office;
use App\Models\Safe;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\DB;

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

            $currency = app(CurrencyService::class)->officeCurrency($office);

            $cashSafe = Safe::withoutGlobalScopes()->create([
                'office_id' => $office->id,
                'name' => 'الصندوق الرئيسي',
                'type' => 'cash',
                'currency' => $currency->code,
                'currency_id' => $currency->id,
                'opening_balance' => 0,
                'is_active' => true,
            ]);

            $bankSafe = Safe::withoutGlobalScopes()->create([
                'office_id' => $office->id,
                'name' => 'البنك',
                'type' => 'bank',
                'currency' => $currency->code,
                'currency_id' => $currency->id,
                'opening_balance' => 0,
                'is_active' => true,
            ]);

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
                ChartOfAccount::withoutGlobalScopes()->create([
                    'office_id' => $office->id,
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'safe_id' => $safeId,
                ]);
            }

            foreach (['operation', 'voucher_receipt', 'voucher_payment', 'safe_transfer'] as $key) {
                DB::table('reference_sequences')->insert([
                    'office_id' => $office->id,
                    'key' => $key,
                    'last_value' => 0,
                ]);
            }

            return $office->fresh();
        });
    }
}
