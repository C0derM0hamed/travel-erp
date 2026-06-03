<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canPerform('create_op') ?? false;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'exists:clients,id'],
            'service_id' => ['required', Rule::exists('services', 'id')->where('active', true)],
            'vendor_id' => ['required', 'exists:vendors,id'],
            'currency' => ['nullable', 'string', 'size:3'],
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
        return [
            'service_id.exists' => 'الخدمة غير موجودة أو غير مفعّلة',
            'initial_payment.lte' => 'الدفعة الأولى لا يمكن أن تتجاوز سعر العميل',
            'vendor_cost.lte' => 'تكلفة المورد لا يمكن أن تتجاوز سعر العميل',
            'date.before_or_equal' => 'تاريخ العملية لا يمكن أن يكون في المستقبل',
            'client_price.min' => 'الحد الأدنى لسعر العميل 1 د.ك',
            'client_price.max' => 'الحد الأقصى للمبلغ 99,999.999 د.ك',
            'vendor_cost.max' => 'الحد الأقصى للتكلفة 99,999.999 د.ك',
        ];
    }
}
