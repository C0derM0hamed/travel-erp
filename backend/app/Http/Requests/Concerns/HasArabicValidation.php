<?php

namespace App\Http\Requests\Concerns;

use App\Support\ArabicMessages;
use Illuminate\Auth\Access\AuthorizationException;

trait HasArabicValidation
{
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(ArabicMessages::FORBIDDEN);
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ArabicMessages::validationAttributes();
    }

    /** @param array<string, string> $extra */
    protected function arabicMessages(array $extra = []): array
    {
        return array_merge(ArabicMessages::commonValidationMessages(), $extra);
    }
}
