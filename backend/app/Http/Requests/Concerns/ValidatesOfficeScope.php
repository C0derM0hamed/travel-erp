<?php

namespace App\Http\Requests\Concerns;

use App\Support\OfficeContext;

trait ValidatesOfficeScope
{
    protected function officeId(): int
    {
        return app(OfficeContext::class)->requireId();
    }

    protected function scopedExists(string $table, string $column = 'id'): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists($table, $column)->where('office_id', $this->officeId());
    }

    protected function scopedUnique(string $table, string $column, ?int $ignoreId = null): \Illuminate\Validation\Rules\Unique
    {
        $rule = \Illuminate\Validation\Rule::unique($table, $column)->where('office_id', $this->officeId());

        if ($ignoreId !== null) {
            $rule->ignore($ignoreId);
        }

        return $rule;
    }
}
