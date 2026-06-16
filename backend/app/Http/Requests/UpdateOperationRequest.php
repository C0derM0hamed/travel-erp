<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use App\Models\Operation;
use App\Models\Voucher;
use App\Support\ArabicMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOperationRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        $operation = $this->route('operation');

        return $operation instanceof Operation && $this->user()?->can('update', $operation);
    }

    public function rules(): array
    {
        /** @var Operation $operation */
        $operation = $this->route('operation');
        $financial = $operation->status === 'new';

        $rules = [
            'notes' => ['nullable', 'string', 'max:2000'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ];

        if ($financial) {
            $rules += [
                'client_id' => ['sometimes', 'required', $this->scopedExists('clients')],
                'service_id' => ['sometimes', 'required', Rule::exists('services', 'id')->where('active', true)],
                'vendor_id' => ['sometimes', 'required', $this->scopedExists('vendors')],
                'currency' => ['nullable', Rule::exists('currencies', 'code')->where('is_active', true)],
                'client_price' => ['sometimes', 'required', 'numeric', 'decimal:0,3', 'min:1', 'max:99999.999'],
                'vendor_cost' => ['sometimes', 'required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999.999'],
                'initial_payment' => ['nullable', 'numeric', 'decimal:0,3', 'gte:0', 'lte:client_price'],
                'payment_method' => ['nullable', Rule::in(['cash', 'bank', 'knet', 'check'])],
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Operation $operation */
            $operation = $this->route('operation');

            if ($operation->status === 'cancelled') {
                $validator->errors()->add('operation', 'لا يمكن تعديل عملية ملغاة');

                return;
            }

            if ($operation->status === 'completed') {
                $validator->errors()->add('operation', 'لا يمكن تعديل عملية مكتملة');

                return;
            }

            if ($operation->status !== 'new') {
                $blocked = ['client_id', 'service_id', 'vendor_id', 'client_price', 'vendor_cost', 'initial_payment', 'payment_method', 'currency'];
                foreach ($blocked as $field) {
                    if ($this->has($field)) {
                        $validator->errors()->add($field, 'لا يمكن تعديل البيانات المالية بعد نقل العملية إلى قيد التنفيذ');
                    }
                }
            }

            if ($operation->status === 'new' && $this->has('initial_payment')) {
                $clientPrice = (float) ($this->input('client_price', $operation->client_price));
                $initial = (float) $this->input('initial_payment');
                if ($initial > $clientPrice + 0.001) {
                    $validator->errors()->add('initial_payment', 'الدفعة الأولى لا يمكن أن تتجاوز سعر العميل');
                }
            }

            if ($operation->status === 'new' && $this->has('vendor_cost')) {
                $clientPrice = (float) ($this->input('client_price', $operation->client_price));
                $vendorCost = (float) $this->input('vendor_cost');
                if ($vendorCost > $clientPrice + 0.001) {
                    $validator->errors()->add('vendor_cost', 'تكلفة المورد لا يمكن أن تتجاوز سعر العميل');
                }
            }

            if ($operation->status === 'new' && $this->hasAny(['client_price', 'vendor_cost', 'initial_payment'])) {
                $extraVouchers = Voucher::where('operation_id', $operation->id)
                    ->whereNull('voided_at')
                    ->count();
                $hasInitial = (float) $operation->initial_payment > 0;
                if ($extraVouchers > ($hasInitial ? 1 : 0)) {
                    $validator->errors()->add('operation', 'لا يمكن تعديل مبالغ العملية بعد إنشاء سندات إضافية');
                }
            }
        });
    }

    public function messages(): array
    {
        return ArabicMessages::operationMessages();
    }
}
