<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        return $this->user()?->role !== 'auditor';
    }

    protected function prepareForValidation(): void
    {
        $phone = PhoneNormalizer::normalize($this->input('phone'));
        $civilId = trim((string) $this->input('civil_id', ''));

        $this->merge([
            'phone' => $phone,
            'civil_id' => $civilId === '' ? null : $civilId,
            'alt_phone' => $this->filled('alt_phone') ? PhoneNormalizer::normalize($this->input('alt_phone')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', $this->scopedUnique('clients', 'phone')],
            'alt_phone' => ['nullable', 'string', 'max:50'],
            'civil_id' => ['nullable', 'string', 'max:50', $this->scopedUnique('clients', 'civil_id')],
            'email' => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'name.required' => 'اسم العميل مطلوب.',
            'phone.required' => 'رقم هاتف العميل مطلوب.',
            'phone.unique' => 'رقم الهاتف مسجل مسبقاً لعميل آخر.',
            'civil_id.unique' => 'الرقم المدني مسجل مسبقاً لعميل آخر.',
        ]);
    }
}
