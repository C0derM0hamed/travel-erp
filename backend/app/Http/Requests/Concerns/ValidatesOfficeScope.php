<?php

namespace App\Http\Requests\Concerns;

use App\Support\OfficeContext;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

trait ValidatesOfficeScope
{
    protected function officeId(): int
    {
        return app(OfficeContext::class)->requireId();
    }

    protected function scopedExists(string $table, string $column = 'id'): Exists
    {
        $rule = \Illuminate\Validation\Rule::exists($table, $column)->where('office_id', $this->officeId());

        if (in_array($table, ['clients', 'operations'], true)) {
            $rule->where(fn ($query) => $query->where('is_hidden', 0)->orWhere('is_hidden', false));
        }

        return $rule;
    }

    protected function scopedUnique(string $table, string $column, ?int $ignoreId = null): Unique
    {
        $rule = \Illuminate\Validation\Rule::unique($table, $column)->where('office_id', $this->officeId());

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }
}
