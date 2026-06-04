<?php

namespace App\Http\Resources;

use App\Services\AccountingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accounting = app(AccountingService::class);

        return [
            'id' => $this->id,
            'ref' => $this->ref,
            'client_id' => $this->client_id,
            'service_id' => $this->service_id,
            'vendor_id' => $this->vendor_id,
            'currency' => $this->currency,
            'currency_label' => 'دينار كويتي',
            'client_price' => (float) $this->client_price,
            'vendor_cost' => (float) $this->vendor_cost,
            'profit' => (float) $this->profit,
            'initial_payment' => (float) $this->initial_payment,
            'payment_method' => $this->payment_method,
            'notes' => $this->notes,
            'status' => $this->status,
            'created_by' => $this->created_by,
            'date' => $this->op_date?->toDateString(),
            'client' => $this->client?->name,
            'service' => $this->service?->name,
            'vendor' => $this->vendor?->name,
            'client_outstanding' => $accounting->operationClientOutstanding($this->id),
            'vendor_outstanding' => $accounting->operationVendorOutstanding($this->id),
        ];
    }
}
