<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSafeRequest extends FormRequest
{
    use HasArabicValidation;

    public function authorize(): bool
    {
        $safe = $this->route('safe');

        return $safe instanceof \App\Models\Safe && $this->user()?->can('update', $safe);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'type' => ['sometimes', 'required', Rule::in(['cash', 'bank'])],
            'currency' => ['nullable', Rule::in(['KWD'])],
            'opening_balance' => ['nullable', 'numeric', 'decimal:0,3', 'gte:0', 'max:999999.999'],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'name.required' => 'اسم الصندوق مطلوب.',
            'type.in' => 'نوع الصندوق غير مسموح.',
        ]);
    }
}
