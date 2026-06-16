<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        return $this->user()?->role !== 'auditor';
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'phone' => $this->filled('phone') ? PhoneNormalizer::normalize($this->input('phone')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->scopedUnique('vendors', 'name')],
            'category' => ['nullable', Rule::in(['airline', 'hotel', 'visa', 'transport', 'other'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'contact' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'opening_balance_amount' => ['nullable', 'numeric', 'decimal:0,3', 'gte:0', 'max:9999999.999'],
            'opening_balance_currency' => ['nullable', \Illuminate\Validation\Rule::exists('currencies', 'code')->where('is_active', true)],
            'opening_balance_type' => ['nullable', \Illuminate\Validation\Rule::in(['receivable', 'payable'])],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'name.required' => 'اسم المورد مطلوب.',
            'name.unique' => 'اسم المورد مسجل مسبقاً.',
        ]);
    }
}
