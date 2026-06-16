<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoucherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $operation = $this->relationLoaded('operation') ? $this->operation : null;

        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'type' => $this->type,
            'party_type' => $this->party_type,
            'party_id' => $this->party_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'currency_code' => $this->currency,
            'currency_id' => $this->currency_id,
            'currency_symbol' => app(CurrencyService::class)->payloadForCode($this->currency, $this->office_id)['symbol'] ?? $this->currency,
            'currency_meta' => app(CurrencyService::class)->payloadForCode($this->currency, $this->office_id),
            'method' => $this->method,
            'safe_id' => $this->safe_id,
            'operation_id' => $this->operation_id,
            'desc' => $this->description ?? '',
            'description' => $this->description ?? '',
            'date' => $this->voucher_date?->toDateString(),
            'created_by' => $this->created_by,
            'reversed' => $operation?->status === 'cancelled',
            'operation_status' => $operation?->status,
        ];
    }
}
