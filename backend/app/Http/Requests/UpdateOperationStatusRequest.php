<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOperationStatusRequest extends FormRequest
{
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
        return [
            'status.in' => 'الحالة المطلوبة غير مسموحة',
        ];
    }
}
