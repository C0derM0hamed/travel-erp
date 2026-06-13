<?php

namespace App\Models\Concerns;

use App\Models\Office;
use App\Support\OfficeContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOffice
{
    protected static function bootBelongsToOffice(): void
    {
        static::addGlobalScope('office', function (Builder $builder) {
            $context = app(OfficeContext::class);

            if ($context->isScopeDisabled() || $context->id() === null) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.office_id', $context->requireId());
        });

        static::creating(function (Model $model) {
            if ($model->office_id) {
                return;
            }

            $context = app(OfficeContext::class);
            if ($context->id() !== null) {
                $model->office_id = $context->requireId();
            }
        });
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
