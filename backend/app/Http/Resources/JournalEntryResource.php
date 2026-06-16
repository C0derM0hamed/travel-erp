<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->entry_date?->toDateString(),
            'ref' => $this->ref,
            'operation_id' => $this->operation_id,
            'voucher_id' => $this->voucher_id,
            'type' => $this->source_type === 'operation' ? 'op' : 'voucher',
            'account' => $this->account?->name,
            'party' => $this->party_type,
            'party_id' => $this->party_id ?? 0,
            'party_name' => $this->party_name ?? '',
            'debit' => (float) $this->debit,
            'credit' => (float) $this->credit,
            'currency' => $this->currency,
            'currency_code' => $this->currency,
            'currency_id' => $this->currency_id,
            'currency_symbol' => app(CurrencyService::class)->payloadForCode($this->currency, $this->office_id)['symbol'] ?? $this->currency,
            'currency_meta' => app(CurrencyService::class)->payloadForCode($this->currency, $this->office_id),
            'desc' => $this->description ?? '',
        ];
    }
}
