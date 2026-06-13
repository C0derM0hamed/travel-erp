<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasHiddenState
{
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_hidden', false);
    }

    public function scopeHidden(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_hidden', true);
    }
}
