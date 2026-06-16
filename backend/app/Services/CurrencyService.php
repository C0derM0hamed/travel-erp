<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Currency;
use App\Models\Office;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrencyService
{
    public function activeCurrencies(): Collection
    {
        return Currency::query()->where('is_active', true)->orderBy('code')->get();
    }

    public function defaultCurrency(): Currency
    {
        $id = AppSetting::query()->whereKey('default_currency_id')->value('value');

        return Currency::query()->whereKey($id)->first()
            ?? Currency::query()->where('code', 'KWD')->first()
            ?? Currency::query()->orderBy('id')->firstOrFail();
    }

    public function officeCurrency(?Office $office = null, ?int $officeId = null): Currency
    {
        $office ??= $officeId ? Office::withoutGlobalScopes()->find($officeId) : null;

        if ($office?->default_currency_id) {
            $currency = Currency::query()->whereKey($office->default_currency_id)->first();
            if ($currency) {
                return $currency;
            }
        }

        return $this->defaultCurrency();
    }

    public function activeByCode(string $code): Currency
    {
        $currency = Currency::query()
            ->where('code', strtoupper($code))
            ->where('is_active', true)
            ->first();

        if (! $currency) {
            throw ValidationException::withMessages([
                'currency' => 'العملة المحددة غير مفعلة أو غير موجودة.',
            ]);
        }

        return $currency;
    }

    public function byCode(?string $code): ?Currency
    {
        if (! $code) {
            return null;
        }

        return Currency::query()->where('code', strtoupper($code))->first();
    }

    public function payload(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'is_active' => (bool) $currency->is_active,
        ];
    }

    public function payloadForCode(?string $code, ?int $officeId = null): array
    {
        $currency = $this->byCode($code) ?? $this->officeCurrency(officeId: $officeId);

        return $this->payload($currency);
    }

    public function setDefault(Currency $currency): void
    {
        if (! $currency->is_active) {
            throw ValidationException::withMessages([
                'currency' => 'لا يمكن تعيين عملة غير مفعلة كعملة افتراضية.',
            ]);
        }

        AppSetting::query()->updateOrCreate(
            ['key' => 'default_currency_id'],
            ['value' => (string) $currency->id],
        );
    }

    public function deactivate(Currency $currency): void
    {
        $defaultId = (int) AppSetting::query()->whereKey('default_currency_id')->value('value');
        if ((int) $currency->id === $defaultId) {
            throw ValidationException::withMessages([
                'currency' => 'لا يمكن تعطيل العملة الافتراضية للنظام.',
            ]);
        }

        if (Office::withoutGlobalScopes()->where('default_currency_id', $currency->id)->exists()) {
            throw ValidationException::withMessages([
                'currency' => 'لا يمكن تعطيل عملة مستخدمة كعملة افتراضية لأحد المكاتب.',
            ]);
        }

        $currency->update(['is_active' => false]);
    }

    public function groupedSums($rows, array $sumFields): array
    {
        return collect($rows)
            ->groupBy(fn ($row) => $row->currency ?: $this->officeCurrency(officeId: $row->office_id ?? null)->code)
            ->map(function ($group, string $code) use ($sumFields) {
                $currency = $this->byCode($code) ?? $this->defaultCurrency();
                $payload = $this->payload($currency);
                foreach ($sumFields as $field) {
                    $payload[$field] = (float) $group->sum($field);
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    public function syncRecordCurrency(string $table, int $id, string $code): void
    {
        $currency = $this->byCode($code);

        DB::table($table)->where('id', $id)->update([
            'currency' => $currency?->code ?? strtoupper($code),
            'currency_id' => $currency?->id,
        ]);
    }
}
