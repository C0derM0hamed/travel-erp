<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use App\Support\ArabicMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        return $this->user()?->canPerform('create_op') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', $this->scopedExists('clients')],
            'service_id' => ['required', Rule::exists('services', 'id')->where('active', true)],
            'vendor_id' => ['required', $this->scopedExists('vendors')],
            'currency' => ['nullable', Rule::exists('currencies', 'code')->where('is_active', true)],
            'client_price' => ['required', 'numeric', 'decimal:0,3', 'min:1', 'max:99999.999'],
            'vendor_cost' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'lte:client_price', 'max:99999.999'],
            'initial_payment' => ['nullable', 'numeric', 'decimal:0,3', 'gte:0', 'lte:client_price'],
            'payment_method' => ['nullable', Rule::in(['cash', 'bank', 'knet', 'check'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return ArabicMessages::operationMessages();
    }
}
