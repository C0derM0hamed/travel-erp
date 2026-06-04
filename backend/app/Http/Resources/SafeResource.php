<?php

namespace App\Http\Resources;

use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SafeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency,
            'initial' => (float) $this->opening_balance,
            'opening_balance' => (float) $this->opening_balance,
            'is_active' => (bool) ($this->is_active ?? true),
            'balance' => app(AccountingService::class)->safeBalance($this->id),
        ];
    }
}
