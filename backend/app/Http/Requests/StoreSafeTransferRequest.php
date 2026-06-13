<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSafeTransferRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\SafeTransfer::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'from_safe_id' => ['required', $this->scopedActiveSafeExists()],
            'to_safe_id' => ['required', $this->scopedActiveSafeExists(), 'different:from_safe_id'],
            'amount' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:99999.999'],
            'currency' => ['nullable', Rule::in(['KWD'])],
            'transfer_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'from_safe_id.required' => 'يرجى اختيار صندوق المصدر.',
            'to_safe_id.required' => 'يرجى اختيار صندوق الوجهة.',
            'to_safe_id.different' => 'يجب أن يكون حساب الوجهة مختلفاً عن حساب المصدر.',
            'amount.required' => 'يرجى إدخال مبلغ التحويل.',
            'amount.min' => 'الحد الأدنى لمبلغ التحويل 0.001 د.ك.',
            'transfer_date.before_or_equal' => 'تاريخ التحويل لا يمكن أن يكون في المستقبل.',
        ]);
    }

    private function scopedActiveSafeExists(): \Illuminate\Validation\Rules\Exists
    {
        return $this->scopedExists('safes')->where('is_active', true);
    }
}
