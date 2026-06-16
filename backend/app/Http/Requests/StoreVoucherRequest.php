<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use App\Models\Client;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Vendor;
use App\Services\AccountingService;
use App\Support\OfficeContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVoucherRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        return $this->user()?->canPerform('create_voucher') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['receipt', 'payment'])],
            'party_type' => ['nullable', Rule::in(['client', 'vendor', 'general'])],
            'party_id' => [
                Rule::requiredIf(fn () => in_array($this->input('party_type'), ['client', 'vendor'], true)),
                'nullable',
                'integer',
            ],
            'amount' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'min:1', 'max:99999.999'],
            'currency' => ['nullable', Rule::exists('currencies', 'code')->where('is_active', true)],
            'method' => ['nullable', Rule::in(['cash', 'bank', 'knet', 'check'])],
            'safe_id' => ['required', $this->scopedExists('safes')],
            'operation_id' => ['nullable', $this->scopedExists('operations')],
            'ref' => ['nullable', 'string', 'max:50', $this->scopedUnique('vouchers', 'ref')],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $officeId = app(OfficeContext::class)->requireId();
            $partyType = $this->input('party_type', 'general');
            $partyId = $this->input('party_id');
            $amount = (float) $this->input('amount');
            $type = $this->input('type');
            $accounting = app(AccountingService::class);

            if ($type === 'payment' && $this->filled('safe_id') && Safe::whereKey($this->input('safe_id'))->exists()) {
                $safeBalance = $accounting->safeBalance((int) $this->input('safe_id'), $officeId);
                if ($amount > $safeBalance + 0.001) {
                    $validator->errors()->add('amount', 'المبلغ يتجاوز رصيد الصندوق/البنك المتاح ('.number_format($safeBalance, 3).')');
                }
            }

            if ($partyType === 'client' && ! Client::whereKey($partyId)->exists()) {
                $validator->errors()->add('party_id', 'العميل المحدد غير موجود');
            }

            if ($partyType === 'vendor' && ! Vendor::whereKey($partyId)->exists()) {
                $validator->errors()->add('party_id', 'المورد المحدد غير موجود');
            }

            if ($this->filled('operation_id')) {
                $operation = Operation::find($this->input('operation_id'));
                if ($operation?->status === 'cancelled') {
                    $validator->errors()->add('operation_id', 'لا يمكن ربط سند بعملية ملغاة');
                } elseif ($operation && $partyType === 'client' && (int) $partyId !== (int) $operation->client_id) {
                    $validator->errors()->add('operation_id', 'العملية لا تتبع هذا العميل');
                } elseif ($operation && $partyType === 'vendor' && (int) $partyId !== (int) $operation->vendor_id) {
                    $validator->errors()->add('operation_id', 'العملية لا تتبع هذا المورد');
                }
            }

            if ($type === 'receipt' && $partyType === 'client' && $partyId && $this->filled('operation_id')) {
                $outstanding = $accounting->operationClientOutstanding((int) $this->input('operation_id'), $officeId);
                
                if ($outstanding <= 0) {
                    $validator->errors()->add('amount', 'لا يوجد رصيد مستحق على هذه العملية');
                } elseif ($amount > $outstanding + 0.001) {
                    $validator->errors()->add('amount', 'المبلغ يتجاوز الرصيد المستحق للعملية ('.number_format($outstanding, 3).')');
                }
            }

            if ($type === 'payment' && $partyType === 'vendor' && $partyId && $this->filled('operation_id')) {
                $owed = $accounting->operationVendorOutstanding((int) $this->input('operation_id'), $officeId);

                if ($owed <= 0) {
                    $validator->errors()->add('amount', 'لا يوجد رصيد مستحق لهذه العملية');
                } elseif ($amount > $owed + 0.001) {
                    $validator->errors()->add('amount', 'المبلغ يتجاوز الرصيد المستحق للعملية ('.number_format($owed, 3).')');
                }
            }
        });
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'type.required' => 'يرجى تحديد نوع السند (قبض أو صرف).',
            'party_id.required' => 'يجب تحديد الطرف عند اختيار عميل أو مورد.',
            'amount.required' => 'يرجى إدخال مبلغ السند.',
            'amount.gt' => 'يجب أن يكون مبلغ السند أكبر من صفر.',
            'amount.min' => 'الحد الأدنى للمبلغ 1.',
            'safe_id.required' => 'يرجى اختيار الصندوق أو الحساب البنكي.',
            'date.before_or_equal' => 'تاريخ السند لا يمكن أن يكون في المستقبل.',
            'currency.exists' => 'العملة المحددة غير مفعلة أو غير موجودة.',
        ]);
    }
}
