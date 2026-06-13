<?php

namespace App\Services;

use App\Models\Safe;
use App\Support\OfficeContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SafeResolver
{
    public function __construct(private OfficeContext $officeContext) {}

    public function resolveForPaymentMethod(string $method, ?int $officeId = null): int
    {
        $officeId ??= $this->officeContext->requireId();
        $type = in_array($method, ['bank', 'knet', 'check'], true) ? 'bank' : 'cash';

        $safe = Safe::withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('type', $type)
            ->when($this->hasIsActiveColumn(), fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->first();

        if (! $safe) {
            throw ValidationException::withMessages([
                'safe_id' => ["لا يوجد صندوق/بنك فعال لطريقة الدفع: {$method}"],
            ]);
        }

        return $safe->id;
    }

    private function hasIsActiveColumn(): bool
    {
        static $exists = null;

        return $exists ??= Schema::hasColumn('safes', 'is_active');
    }
}
