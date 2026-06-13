<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSafeRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\Safe::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'currency' => ['nullable', Rule::in(['KWD'])],
            'opening_balance' => ['nullable', 'numeric', 'decimal:0,3', 'gte:0', 'max:999999.999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'name.required' => 'اسم الصندوق مطلوب.',
            'type.required' => 'يرجى تحديد نوع الصندوق (نقدي أو بنكي).',
            'type.in' => 'نوع الصندوق غير مسموح.',
            'currency.in' => 'النظام المحاسبي يدعم الدينار الكويتي فقط حالياً.',
        ]);
    }
}
