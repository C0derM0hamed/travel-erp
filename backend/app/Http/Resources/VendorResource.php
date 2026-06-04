<?php

namespace App\Http\Resources;

use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray() + [
            'balance' => app(AccountingService::class)->vendorBalance($this->id),
        ];
    }
}
