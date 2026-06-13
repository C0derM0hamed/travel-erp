<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOperationStatusRequest extends FormRequest
{
    use HasArabicValidation;

    public function authorize(): bool
    {
        return $this->user()?->canPerform('update_op_status') ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['processing', 'completed'])],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'status.required' => 'يرجى تحديد الحالة الجديدة.',
            'status.in' => 'الحالة المطلوبة غير مسموحة.',
        ]);
    }
}
