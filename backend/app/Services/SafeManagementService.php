<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Safe;
use App\Support\OfficeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SafeManagementService
{
    public function __construct(private OfficeContext $officeContext) {}

    public function create(array $data): Safe
    {
        $officeId = $this->officeContext->requireId();

        return DB::transaction(function () use ($data, $officeId) {
            $safe = Safe::create([
                'office_id' => $officeId,
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => $data['currency'] ?? 'KWD',
                'opening_balance' => $data['opening_balance'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            ChartOfAccount::withoutGlobalScopes()->create([
                'office_id' => $officeId,
                'code' => $this->nextAccountCode($officeId),
                'name' => $safe->name,
                'type' => 'asset',
                'safe_id' => $safe->id,
            ]);

            return $safe->fresh('account');
        });
    }

    public function update(Safe $safe, array $data): Safe
    {
        $safe->update(collect($data)->only(['name', 'type', 'currency', 'opening_balance'])->filter(fn ($v) => $v !== null)->all());

        if ($safe->wasChanged('name') && $safe->account) {
            $safe->account->update(['name' => $safe->name]);
        }

        return $safe->fresh('account');
    }

    public function toggleActive(Safe $safe): Safe
    {
        $safe->update(['is_active' => ! $safe->is_active]);

        return $safe->fresh('account');
    }

    private function nextAccountCode(int $officeId): string
    {
        $max = ChartOfAccount::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('code', 'like', '100%')
            ->pluck('code')
            ->map(fn (string $code) => (int) $code)
            ->max() ?? 1000;

        $next = max($max + 1, 1003);

        if (ChartOfAccount::withoutGlobalScopes()->where('office_id', $officeId)->where('code', (string) $next)->exists()) {
            throw ValidationException::withMessages([
                'safe' => 'تعذر تخصيص رمز حساب للصندوق الجديد.',
            ]);
        }

        return (string) $next;
    }
}
