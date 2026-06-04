<?php

namespace App\Http\Resources;

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
            'desc' => $this->description ?? '',
        ];
    }
}
