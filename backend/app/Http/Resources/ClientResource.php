<?php

namespace App\Http\Resources;

use App\Models\Operation;
use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accounting = app(AccountingService::class);

        return $this->resource->toArray() + [
            'nationality' => $this->nationality ?? '',
            'balance' => $accounting->clientBalance($this->id),
            'operations_count' => Operation::where('client_id', $this->id)->where('status', '!=', 'cancelled')->count(),
        ];
    }
}
