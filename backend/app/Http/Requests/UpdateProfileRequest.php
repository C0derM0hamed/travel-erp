<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    use HasArabicValidation;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'name.required' => 'الاسم مطلوب.',
            'name.max' => 'الاسم طويل جداً.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'يرجى إدخال بريد إلكتروني صحيح.',
            'email.unique' => 'البريد الإلكتروني مستخدم من قبل مستخدم آخر.',
        ]);
    }
}
